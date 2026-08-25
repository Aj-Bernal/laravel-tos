<?php

namespace App\Services\Bloom;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Generates exam questions from lesson content, constrained to match
 * a Table of Specification's per-Bloom's-level item counts.
 *
 * Supports THREE question types, auto-mixed per Bloom's level:
 *   - multiple_choice      Remembering / Understanding
 *   - modified_true_false  Applying / Analyzing / Evaluating / Creating
 *   - enumeration          mixed in at every level as the second option
 *
 * The split within a level is 50/50 (largest-remainder apportioned via
 * ApportionmentService, so it never silently drops an item). Adjust
 * TYPE_MIX_LOWER / TYPE_MIX_HIGHER / LOWER_LEVELS below if you want a
 * different ratio or grouping.
 *
 * Uses the Google Gemini API (free tier). Set GEMINI_API_KEY in your .env.
 * (config/services.php should have: 'gemini' => ['key' => env('GEMINI_API_KEY')])
 */
class ExamGeneratorService
{
    private const MODEL = 'gemini-2.5-flash-lite';
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent';

    /** Bloom's levels that get the "lower order" type mix. */
    private const LOWER_LEVELS = ['Remembering', 'Understanding'];

    private const TYPE_MIX_LOWER = [
        'multiple_choice' => 0.5,
        'enumeration' => 0.5,
    ];

    private const TYPE_MIX_HIGHER = [
        'modified_true_false' => 0.5,
        'enumeration' => 0.5,
    ];

    private const TYPE_LABELS = [
        'multiple_choice' => 'Multiple Choice',
        'modified_true_false' => 'Modified True or False',
        'enumeration' => 'Enumeration',
    ];

    /** HTTP statuses worth retrying — rate limit and transient overload. */
    private const RETRYABLE_STATUSES = [429, 503];

    /** Max retry attempts and initial backoff (seconds, doubles each attempt). */
    private const MAX_RETRIES = 3;
    private const INITIAL_BACKOFF_SECONDS = 3;

    /**
     * Firing every lesson's Gemini call at once via Http::pool blows past
     * the free tier's requests-per-minute cap in one burst, so every
     * request in the pool comes back 429 together and even the sequential
     * retry-with-backoff can't recover (they all retry into the same
     * exhausted window). Chunking the pool keeps some concurrency while
     * staying under the per-minute limit — tune BATCH_SIZE down (or
     * BATCH_DELAY_SECONDS up) if you still see 429s across a whole batch.
     */
    private const BATCH_SIZE = 3;
    private const BATCH_DELAY_SECONDS = 20;

    /**
     * Single-lesson generation (kept for backward compatibility / simple use).
     *
     * @param string $lessonText Extracted PDF lesson content
     * @param array $tosDistribution Level => ['item_count' => int, ...]
     * @return array{questions: array, raw_response: ?string}
     */
    public function generate(string $lessonText, array $tosDistribution, string $subject = 'General'): array
    {
        $apiKey = $this->apiKey();
        $typeCounts = $this->autoAssignTypes($tosDistribution);
        $prompt = $this->buildPrompt($lessonText, $typeCounts, $subject);

        $response = $this->postWithRetry($prompt, $apiKey);

        if ($response->failed()) {
            throw new RuntimeException('Exam generation API call failed: ' . $response->body());
        }

        $text = $this->extractText($response->json());
        $questions = $this->parseQuestionsJson($text);

        return [
            'questions' => $questions,
            'raw_response' => $text,
        ];
    }

    /**
     * Generate exam questions for MULTIPLE lessons concurrently, one Gemini
     * API call per lesson, fired in parallel via Http::pool.
     *
     * @param array $lessonJobs Keyed by lesson_id => [
     *     'lesson_text' => string,
     *     'level_counts' => array (Bloom level => ['item_count' => int, ...]),
     *     'lesson_title' => string,
     * ]
     * @param string $course Course/subject name, used in the prompt
     * @return array Keyed by lesson_id => ['questions' => array, 'error' => ?string]
     */
    public function generateForLessons(array $lessonJobs, string $course = 'General'): array
    {
        $apiKey = $this->apiKey();

        $lessonIds = array_keys($lessonJobs);
        $typeCountsByLesson = [];
        $results = [];

        $batches = array_chunk($lessonIds, self::BATCH_SIZE);

        foreach ($batches as $batchIndex => $batchLessonIds) {
            if ($batchIndex > 0) {
                sleep(self::BATCH_DELAY_SECONDS);
            }

            $responses = Http::pool(function ($pool) use ($lessonJobs, $apiKey, $course, $batchLessonIds, &$typeCountsByLesson) {
                $requests = [];
                foreach ($batchLessonIds as $lessonId) {
                    $job = $lessonJobs[$lessonId];
                    $typeCounts = $this->autoAssignTypes($job['level_counts']);
                    $typeCountsByLesson[$lessonId] = $typeCounts;

                    $prompt = $this->buildPrompt(
                        $job['lesson_text'],
                        $typeCounts,
                        $course,
                        $job['lesson_title'] ?? null
                    );

                    $requests[] = $pool->withHeaders($this->headers($apiKey))
                        ->timeout(120)
                        ->post(self::API_URL, $this->body($prompt));
                }

                return $requests;
            });

            foreach ($batchLessonIds as $index => $lessonId) {
                $response = $responses[$index];

                // A batch can still trip the limit together. If a lesson's
                // request came back 429/503, retry it here ONE AT A TIME
                // (not pooled) with backoff — sequential + delayed is far
                // less likely to re-hit the same burst limit than firing it
                // again alongside its batch-mates would be.
                if (!($response instanceof \Throwable) && in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                    Log::info("[ExamGen] Lesson {$lessonId} hit {$response->status()} in the pool — retrying sequentially.");

                    $job = $lessonJobs[$lessonId];
                    $prompt = $this->buildPrompt(
                        $job['lesson_text'],
                        $typeCountsByLesson[$lessonId],
                        $course,
                        $job['lesson_title'] ?? null
                    );

                    try {
                        $response = $this->postWithRetry($prompt, $apiKey);
                    } catch (\Throwable $e) {
                        $response = $e;
                    }
                }

                try {
                    if ($response instanceof \Throwable) {
                        throw $response;
                    }

                    if ($response->failed()) {
                        throw new RuntimeException('API call failed: ' . $response->body());
                    }

                    $text = $this->extractText($response->json());
                    $questions = $this->parseQuestionsJson($text);

                    $job = $lessonJobs[$lessonId];
                    $questions = $this->backfillMissingLevels(
                        $questions,
                        $typeCountsByLesson[$lessonId],
                        $job['lesson_text'],
                        $course,
                        $job['lesson_title'] ?? null,
                        $apiKey
                    );

                    $results[$lessonId] = [
                        'questions' => $questions,
                        'error' => null,
                    ];
                } catch (\Throwable $e) {
                    $title = $lessonJobs[$lessonId]['lesson_title'] ?? "Lesson {$lessonId}";
                    Log::error("[ExamGen] Lesson {$lessonId} ('{$title}') failed: " . $e->getMessage());
                    $results[$lessonId] = [
                        'questions' => [],
                        'error' => "{$title}: " . $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Splits each Bloom's level's item quota across question types.
     *
     * Remembering / Understanding -> Multiple Choice + Enumeration (50/50)
     * Applying / Analyzing / Evaluating / Creating -> Modified True-or-False + Enumeration (50/50)
     *
     * @param array $levelCounts Bloom level => ['item_count' => int, ...]
     * @return array<string, array<string, int>> Bloom level => [type => count]
     */
    private function autoAssignTypes(array $levelCounts): array
    {
        $byLevel = [];

        foreach ($levelCounts as $level => $d) {
            $n = (int) ($d['item_count'] ?? 0);
            if ($n <= 0) {
                continue;
            }

            $mix = in_array($level, self::LOWER_LEVELS, true)
                ? self::TYPE_MIX_LOWER
                : self::TYPE_MIX_HIGHER;

            $raw = [];
            foreach ($mix as $type => $weight) {
                $raw[$type] = $weight * $n;
            }

            $counts = ApportionmentService::apportion($raw, $n);
            $counts = array_filter($counts, fn ($c) => $c > 0);

            if (!empty($counts)) {
                $byLevel[$level] = $counts;
            }
        }

        return $byLevel;
    }

    /**
     * Compares generated questions against the requested per-level-per-type
     * counts. If any (level, type) combination came back short (or missing
     * entirely), fires a small follow-up request asking ONLY for the
     * missing items and merges the results in. Runs at most 2 extra passes
     * to avoid infinite loops if the model keeps refusing a combination.
     *
     * @param array<string, array<string, int>> $typeCounts Bloom level => [type => count]
     */
    private function backfillMissingLevels(
        array $questions,
        array $typeCounts,
        string $lessonText,
        string $course,
        ?string $lessonTitle,
        string $apiKey
    ): array {
        for ($pass = 0; $pass < 3; $pass++) {
            $have = collect($questions)->countBy(
                fn ($q) => ($q['bloom_level'] ?? '') . '|' . ($q['question_type'] ?? 'multiple_choice')
            );

            $missing = [];
            foreach ($typeCounts as $level => $types) {
                foreach ($types as $type => $n) {
                    $got = $have[$level . '|' . $type] ?? 0;
                    $need = max(0, $n - $got);
                    if ($need > 0) {
                        $missing[$level][$type] = $need;
                    }
                }
            }

            if (empty($missing)) {
                break;
            }

            $lines = [];
            foreach ($missing as $level => $types) {
                foreach ($types as $type => $n) {
                    $label = self::TYPE_LABELS[$type] ?? $type;
                    $lines[] = "- {$level} ({$label}): {$n} item(s)";
                }
            }
            $missingBreakdown = implode("\n", $lines);

            $titleLine = $lessonTitle ? "LESSON: {$lessonTitle}\n" : '';

            $prompt = <<<PROMPT
You are an expert exam item writer for Philippine DepEd classrooms, following Bloom's Taxonomy.

COURSE/SUBJECT: {$course}
{$titleLine}
LESSON CONTENT:
---
{$lessonText}
---

A previous pass failed to generate questions for these specific Bloom's Taxonomy level + question type combinations. Generate ONLY these now, exactly this many, with NO exceptions and NO empty results:
{$missingBreakdown}

Even for higher-order levels like Analyzing, Evaluating, or Creating on basic content, write your best-effort question that reasonably applies, compares, judges, or extends the lesson concepts. Do not skip any requested combination.

{$this->typeSchemaInstructions()}

Return ONLY valid JSON, no markdown fences, matching:
{
  "questions": [ /* mixed question_type objects as described above */ ]
}
PROMPT;

            try {
                $response = $this->postWithRetry($prompt, $apiKey);

                if ($response->failed()) {
                    break;
                }

                $text = $this->extractText($response->json());
                $extra = $this->parseQuestionsJson($text);

                if (empty($extra)) {
                    break;
                }

                $questions = array_merge($questions, $extra);
            } catch (\Throwable $e) {
                Log::warning("[ExamGen] Backfill pass {$pass} threw for lesson '{$lessonTitle}': " . $e->getMessage());
                break;
            }
        }

        $stillMissing = $this->countStillMissing($questions, $typeCounts);
        if ($stillMissing > 0) {
            Log::warning("[ExamGen] Lesson '{$lessonTitle}' is short {$stillMissing} item(s) after all backfill passes — check maxOutputTokens / prompt complexity for this quota size.");
        }

        return $questions;
    }

    /**
     * @param array<string, array<string, int>> $typeCounts Bloom level => [type => count]
     */
    private function countStillMissing(array $questions, array $typeCounts): int
    {
        $have = collect($questions)->countBy(
            fn ($q) => ($q['bloom_level'] ?? '') . '|' . ($q['question_type'] ?? 'multiple_choice')
        );

        $missing = 0;
        foreach ($typeCounts as $level => $types) {
            foreach ($types as $type => $n) {
                $got = $have[$level . '|' . $type] ?? 0;
                $missing += max(0, $n - $got);
            }
        }

        return $missing;
    }

    /**
     * Single sequential POST with exponential-backoff retry for rate-limit
     * (429) or transient overload (503) responses, and for network-level
     * connection exceptions. Not used inside the Http::pool() closure
     * itself (pool requests must stay non-blocking) — only for the
     * single-lesson path, the backfill pass, and the sequential retry of
     * pool responses that came back retryable.
     */
    private function postWithRetry(string $prompt, string $apiKey): \Illuminate\Http\Client\Response
    {
        $attempt = 0;
        $delay = self::INITIAL_BACKOFF_SECONDS;

        while (true) {
            try {
                $response = Http::withHeaders($this->headers($apiKey))
                    ->timeout(120)
                    ->post(self::API_URL, $this->body($prompt));
            } catch (\Throwable $e) {
                $response = null;
            }

            $retryable = $response === null || in_array($response->status(), self::RETRYABLE_STATUSES, true);

            if (!$retryable || $attempt >= self::MAX_RETRIES) {
                if ($response === null) {
                    throw new RuntimeException('Exam generation API call failed with a network/connection error after retries.');
                }

                return $response;
            }

            $attempt++;
            Log::info('[ExamGen] Retrying Gemini call after '
                . ($response ? $response->status() : 'a connection error')
                . " (attempt {$attempt}/" . self::MAX_RETRIES . "), waiting {$delay}s.");

            sleep($delay);
            $delay *= 2;
        }
    }

    private function apiKey(): string
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not configured in .env / config/services.php');
        }

        return $apiKey;
    }

    private function headers(string $apiKey): array
    {
        return [
            'x-goog-api-key' => $apiKey,
            'content-type' => 'application/json',
        ];
    }

    private function body(string $prompt): array
    {
        return [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                // Gemini 2.5 models spend part of maxOutputTokens on hidden
                // "thinking" tokens before writing the actual JSON. This task
                // needs no chain-of-thought — thinkingBudget: 0 turns that off
                // so the full token budget goes to the question objects
                // themselves (faster and cheaper too). 8192 (up from 4096)
                // gives headroom for a large lesson's full item count (~19+
                // fully-detailed question objects) without truncating the
                // JSON array mid-generation.
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ];
    }

    private function extractText(?array $data): string
    {
        $parts = collect($data['candidates'] ?? [])->first()['content']['parts'] ?? [];

        $text = collect($parts)->pluck('text')->implode("\n");

        if (trim($text) === '') {
            throw new RuntimeException('Empty response from Gemini API: ' . json_encode($data));
        }

        return $text;
    }

    /**
     * Shared JSON-schema explanation for all three question types, reused
     * by both the main prompt and the backfill prompt.
     */
    private function typeSchemaInstructions(): string
    {
        return <<<TXT
NOTATION: whenever a question involves a mathematical expression, formula,
equation, or algorithmic notation (e.g. Big-O complexity, set notation,
summations, matrix/array indices), write it in LaTeX inside the "question"
text — inline as \$...\$ or, for a standalone equation on its own line, as
\$\$...\$\$. Do not describe math in plain words when LaTeX can express it
exactly (e.g. write "\$O(n \\log n)\$", not "O of n log n"). Leave ordinary
prose outside the \$...\$ delimiters as normal text.

Each question object's shape depends on its "question_type":

1. "multiple_choice":
{
  "question_type": "multiple_choice",
  "bloom_level": "Remembering",
  "question": "...",
  "options": {"A": "...", "B": "...", "C": "...", "D": "..."},
  "correct_answer": "A",
  "rationale": "short reason why this tests this Bloom's level"
}

2. "modified_true_false": statement to be judged true or false. If false,
   "correction" MUST contain the corrected version of the statement so it
   would then be true. If true, set "correction" to null.
{
  "question_type": "modified_true_false",
  "bloom_level": "Applying",
  "question": "the statement to evaluate",
  "is_true": false,
  "correction": "the corrected statement (or null if is_true is true)",
  "rationale": "short reason why this tests this Bloom's level"
}

3. "enumeration": a prompt asking students to list items; "accepted_answers"
   is the full list of correct items expected (in any order).
{
  "question_type": "enumeration",
  "bloom_level": "Understanding",
  "question": "the enumeration prompt, e.g. 'Enumerate the three branches of government.'",
  "accepted_answers": ["...", "...", "..."],
  "rationale": "short reason why this tests this Bloom's level"
}
TXT;
    }

    /**
     * @param array<string, array<string, int>> $typeCounts Bloom level => [type => count]
     */
    private function buildPrompt(string $lessonText, array $typeCounts, string $subject, ?string $lessonTitle = null): string
    {
        $lines = [];
        $totalItems = 0;
        foreach ($typeCounts as $level => $types) {
            foreach ($types as $type => $n) {
                $label = self::TYPE_LABELS[$type] ?? $type;
                $lines[] = "- {$level} ({$label}): {$n} item(s)";
                $totalItems += $n;
            }
        }
        $itemBreakdown = implode("\n", $lines);

        $titleLine = $lessonTitle ? "LESSON: {$lessonTitle}\n" : '';

        return <<<PROMPT
You are an expert exam item writer for Philippine DepEd classrooms, following Bloom's Taxonomy Table of Specification requirements.

COURSE/SUBJECT: {$subject}
{$titleLine}
LESSON CONTENT (source material to base questions on):
---
{$lessonText}
---

Generate exactly {$totalItems} exam questions based ONLY on the lesson content above, distributed EXACTLY as follows across Bloom's Taxonomy level AND question type:
{$itemBreakdown}

Rules:
1. Each question must genuinely require thinking at its assigned Bloom's level (e.g. "Analyzing" questions must require breaking down/comparing/relating ideas, not just recall).
2. Each question must be answerable strictly from the lesson content provided.
3. Do not repeat concepts across questions unnecessarily; spread coverage across the lesson.
4. IMPORTANT: You must generate the exact requested count for EVERY level+type combination listed, even for higher levels like Analyzing, Evaluating, or Creating on introductory/definitional content. If the lesson content is basic, write higher-level questions that ask students to apply, compare, judge the usefulness of, or extend the concepts in the lesson. Do NOT return an empty "questions" array and do NOT skip any requested combination under any circumstance — always produce your best-effort question for every requested item.
5. {$this->typeSchemaInstructions()}
6. Return ONLY valid JSON (no markdown fences, no preamble, no explanation) matching this exact structure:

{
  "questions": [ /* mixed question_type objects per the schemas above, exactly matching the requested counts */ ]
}
PROMPT;
    }

    private function parseQuestionsJson(string $text): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(json)?/', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
            throw new RuntimeException('Failed to parse exam questions JSON from model response.');
        }

        // Filter out any individual items that don't match their declared
        // question_type's required shape, rather than failing the whole
        // batch — the backfill pass will pick up the slack for whatever
        // gets dropped here.
        $valid = array_values(array_filter($decoded['questions'], [$this, 'isValidQuestion']));

        return $valid;
    }

    private function isValidQuestion(mixed $q): bool
    {
        if (!is_array($q) || empty($q['bloom_level']) || empty($q['question'])) {
            return false;
        }

        $type = $q['question_type'] ?? 'multiple_choice';

        return match ($type) {
            'multiple_choice' => isset($q['options']['A'], $q['options']['B'], $q['options']['C'], $q['options']['D'])
                && in_array($q['correct_answer'], ['A', 'B', 'C', 'D'], true),
            'modified_true_false' => array_key_exists('is_true', $q)
                && ($q['is_true'] === true || !empty($q['correction'])),
            'enumeration' => !empty($q['accepted_answers']) && is_array($q['accepted_answers']),
            default => false,
        };
    }
}