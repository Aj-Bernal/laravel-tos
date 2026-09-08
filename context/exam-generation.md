# Exam generation (Gemini, concurrent per-lesson)

## Related File Index

| File Location | Function |
|---|---|
| `app/Services/Bloom/ExamGeneratorService.php` | Prompts, `Http::pool` fan-out, sequential retry, JSON parse/validate, backfill |
| `app/Services/Bloom/PdfTextExtractorService.php` | PDF text extraction (`smalot/pdfparser`) + `truncateForPrompt` |
| `app/Services/Bloom/ApportionmentService.php` | Splits each level's quota across question types |
| `config/services.php` | `gemini.key` ← `GEMINI_API_KEY` env |

## API setup

- Provider is **Google Gemini**, model `gemini-2.5-flash-lite`
  (`API_URL = .../v1beta/models/<model>:generateContent`).
- Key source: `config('services.gemini.key')` ← `GEMINI_API_KEY` in `.env`.
  Missing key throws `RuntimeException`. Header: `x-goog-api-key`.
- Request body: `contents[].parts[].text = $prompt`, `generationConfig =
  {maxOutputTokens: 4096, responseMimeType: "application/json"}`.
- README references to Anthropic are stale — ignore them.

## Concurrency + retry (`generateForLessons`)

- One Gemini call per lesson, fired concurrently via `Http::pool()` (120s timeout each).
- Pool responses with **429/503 are retried SEQUENTIALLY** (one at a time, not pooled)
  via `postWithRetry`: max 3 attempts, backoff 3s doubling. Connection exceptions
  also retried. Other 4xx fail fast → per-lesson `error` string, siblings unaffected.
- Return shape: `lesson_id => ['questions' => [...], 'error' => ?string]`.
- Controller wraps calls in `set_time_limit(300)` — keep it; removing it kills
  multi-lesson batches mid-request.

## Type mix (`autoAssignTypes`)

- Remembering/Understanding → `multiple_choice` + `enumeration` (50/50).
- Applying/Analyzing/Evaluating/Creating → `modified_true_false` + `enumeration` (50/50).
- Split via `ApportionmentService` so counts sum exactly. Tune via
  `TYPE_MIX_LOWER` / `TYPE_MIX_HIGHER` / `LOWER_LEVELS` constants.

## Question schemas (must match `isValidQuestion`)

- `multiple_choice`: `options.{A,B,C,D}` all present + non-empty `correct_answer`.
- `modified_true_false`: `is_true` present; if false, `correction` non-empty (null when true).
- `enumeration`: `accepted_answers` non-empty array.
- All: non-empty `bloom_level` + `question`. Invalid items are **filtered out**
  (whole batch is NOT failed) — then `backfillMissingLevels` fires up to 2
  follow-up prompts asking ONLY for missing level+type combos and merges results.
- `parseQuestionsJson` strips ``` fences; unparseable top-level JSON throws.
  Verbose `Log::info/warning [ExamGen]` lines are marked TEMP DIAGNOSTIC — safe to remove.

## PDF text input

- `PdfTextExtractorService` (`smalot/pdfparser`): `extractFromPath` + whitespace
  collapse. Controller truncates to **10 000 chars** before prompting
  (`truncateForPrompt($text, 10000)` — note service default is 12 000; controller wins).
- Empty text ⇒ lesson skipped (batch) or 422 (single retry). Scanned/image PDFs
  have no text layer — need external OCR, not implemented here.
