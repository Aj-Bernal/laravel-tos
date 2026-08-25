<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\TableOfSpecification;
use App\Policies\TosPolicy;
use App\Services\Bloom\BloomClassifierService;
use App\Services\Bloom\ExamGeneratorService;
use App\Services\Bloom\ItemPlacementService;
use App\Services\Bloom\PdfTextExtractorService;
use App\Services\Bloom\TosBuilderService;
use App\Services\Latex\LatexMathRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TosController extends Controller
{
    public function __construct(
        private BloomClassifierService $classifier,
        private TosBuilderService $tosBuilder,
        private PdfTextExtractorService $pdfExtractor,
        private ExamGeneratorService $examGenerator,
        private TosPolicy $tosPolicy,
        private ItemPlacementService $itemPlacement,
        private LatexMathRenderer $latexRenderer,
    ) {}

    /**
     * Aborts with 403 unless the current user (or "no one, TOS is
     * ownerless") is allowed to view/act on this TOS. Call this at the top
     * of every method that receives a route-bound TableOfSpecification.
     */
    private function authorizeTos(TableOfSpecification $tos): void
    {
        abort_unless($this->tosPolicy->access(Auth::user(), $tos), 403);
    }

    /**
     * Atomically claims a lesson for generation, closing the double-submit
     * race: two concurrent requests both reading generation_status as
     * 'pending' and then separately writing 'generating' would still
     * race, since the read and the write are two separate steps. A single
     * conditional UPDATE ... WHERE doesn't have that gap — the database
     * only lets one of the two competing UPDATEs actually match a row, so
     * only one caller ever sees $claimed === 1.
     *
     * Also reclaims stale locks: if a previous attempt died mid-generation
     * (server timeout, killed worker) without ever reaching
     * persistLessonResults() to release the lock, generation_started_at
     * will be old and the lesson becomes claimable again instead of being
     * stuck 'generating' forever.
     */
    private function claimLessonForGeneration(Lesson $lesson, int $staleAfterMinutes = 10): bool
    {
        $staleThreshold = now()->subMinutes($staleAfterMinutes);

        $claimed = Lesson::query()
            ->where('id', $lesson->id)
            ->where(function ($query) use ($staleThreshold) {
                $query->whereIn('generation_status', [Lesson::STATUS_PENDING, Lesson::STATUS_FAILED])
                    ->orWhere(function ($query) use ($staleThreshold) {
                        $query->where('generation_status', Lesson::STATUS_GENERATING)
                            ->where('generation_started_at', '<', $staleThreshold);
                    });
            })
            ->update([
                'generation_status' => Lesson::STATUS_GENERATING,
                'generation_started_at' => now(),
            ]);

        return $claimed === 1;
    }

    /**
     * Single-page entry point. Renders the create form (no $tos) or a
     * direct/shared link to an already-built TOS (with $tos) — both use
     * the exact same view, and every action from here on happens via
     * fetch() against the JSON endpoints below, never a full navigation.
     */
    public function create()
    {
        return view('tos.index');
    }

    public function show(Request $request, TableOfSpecification $tos)
    {
        $this->authorizeTos($tos);

        $tos->load('lessons.objectives', 'lessons.examQuestions');
        $itemPlacement = $this->itemPlacement->group($tos);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('tos._results', ['tos' => $tos, 'itemPlacement' => $itemPlacement])->render(),
                'tos_id' => $tos->uuid,
                'course' => $tos->course,
                'has_exam' => $tos->examQuestions()->exists(),
            ]);
        }

        return view('tos.index', compact('tos', 'itemPlacement'));
    }

    /**
     * Renders the TOS as a downloadable PDF matching the CvSU Arts and
     * Sciences Department's official Table of Specification layout.
     *
     * Fields the current data model doesn't capture (department, semester,
     * exam period, course outcome text, and the three signature names/
     * titles) are accepted as optional query-string overrides rather than
     * new persisted columns — this keeps the export a "fill in the paper
     * form" step instead of forcing every TOS creation flow to collect
     * fields most callers won't need. Anything omitted renders as a blank
     * line for the teacher to fill in by hand, same as printing a blank
     * paper form.
     */
    public function exportPdf(Request $request, TableOfSpecification $tos)
    {
        $this->authorizeTos($tos);

        $validated = $request->validate([
            'department' => 'sometimes|string|max:255',
            'semester' => 'sometimes|string|max:255',
            'academic_year' => 'sometimes|string|max:255',
            'exam_period' => 'sometimes|in:Midterms,Finals',
            'course_outcome' => 'sometimes|string|max:1000',
            'prepared_by' => 'sometimes|string|max:255',
            'prepared_by_title' => 'sometimes|string|max:255',
            'reviewed_by' => 'sometimes|string|max:255',
            'reviewed_by_title' => 'sometimes|string|max:255',
            'approved_by' => 'sometimes|string|max:255',
            'approved_by_title' => 'sometimes|string|max:255',
        ]);

        $tos->load('lessons.objectives', 'lessons.examQuestions');
        $itemPlacementGrid = $this->itemPlacement->groupGrid($tos);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tos.pdf', [
            'tos' => $tos,
            'itemPlacementGrid' => $itemPlacementGrid,
            'meta' => $validated,
        ])->setPaper('legal', 'landscape');

        $filename = 'TOS-'.\Illuminate\Support\Str::slug($tos->course).'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Renders the GENERATED EXAM (questions + options, not the TOS grid) as
     * a downloadable PDF. Unlike exportPdf() above, this is where the LaTeX
     * Gemini writes into question text (see
     * ExamGeneratorService::typeSchemaInstructions) actually needs to
     * appear — but DomPDF can't execute the KaTeX auto-render JS the web
     * results view uses, so every math-bearing field is pre-rendered to
     * inline SVG via LatexMathRenderer (server-side MathJax, one Node call
     * for the whole exam) BEFORE it ever reaches the Blade view. The view
     * itself just prints already-safe HTML with {!! !!} — no LaTeX handling
     * happens in the template.
     */
    public function exportExamPdf(Request $request, TableOfSpecification $tos)
    {
        $this->authorizeTos($tos);

        $tos->load('lessons.examQuestions');

        $lessons = $tos->lessons
            ->filter(fn (Lesson $lesson) => $lesson->examQuestions->isNotEmpty())
            ->values();

        if ($lessons->isEmpty()) {
            return $this->respondError($request, 'No generated questions yet — generate the exam first.');
        }

        // ---- Pass 1: collect every math-bearing field across every
        // question into one flat map, keyed so we can find each field again
        // after rendering. Options/accepted_answers are collections of
        // strings, so each gets its own compound key (e.g. "42.opt.A").
        $fields = [];
        foreach ($lessons as $lesson) {
            foreach ($lesson->examQuestions as $q) {
                $fields["{$q->id}.question"] = $q->question;
                $fields["{$q->id}.rationale"] = $q->rationale;
                $fields["{$q->id}.correction"] = $q->correction;

                foreach ($q->options ?? [] as $letter => $option) {
                    $fields["{$q->id}.opt.{$letter}"] = $option;
                }
                foreach ($q->accepted_answers ?? [] as $i => $answer) {
                    $fields["{$q->id}.ans.{$i}"] = $answer;
                }
            }
        }

        // ---- Pass 2: one batched render (one Node process for the whole
        // exam, not one per question/field — see LatexMathRenderer).
        $rendered = $this->latexRenderer->renderStrings($fields);

        // ---- Pass 3: reshape into the plain array structure the Blade
        // view expects, so the template has zero LaTeX-handling logic.
        $examData = [];
        foreach ($lessons as $lesson) {
            $questions = [];

            foreach ($lesson->examQuestions as $q) {
                $optionsHtml = [];
                foreach ($q->options ?? [] as $letter => $option) {
                    $optionsHtml[$letter] = $rendered["{$q->id}.opt.{$letter}"] ?? e($option);
                }

                $answersHtml = [];
                foreach ($q->accepted_answers ?? [] as $i => $answer) {
                    $answersHtml[] = $rendered["{$q->id}.ans.{$i}"] ?? e($answer);
                }

                $questions[] = [
                    'item_number' => $q->item_number,
                    'bloom_level' => $q->bloom_level,
                    'question_type' => $q->question_type ?? 'multiple_choice',
                    'question_html' => $rendered["{$q->id}.question"] ?? e($q->question),
                    'options_html' => $optionsHtml,
                    'correct_answer' => $q->correct_answer,
                    'is_true' => $q->is_true,
                    'correction_html' => $q->correction ? ($rendered["{$q->id}.correction"] ?? e($q->correction)) : null,
                    'accepted_answers_html' => $answersHtml,
                    'rationale_html' => $q->rationale ? ($rendered["{$q->id}.rationale"] ?? e($q->rationale)) : null,
                ];
            }

            $examData[] = [
                'title' => $lesson->title,
                'letter' => $lesson->letter,
                'questions' => $questions,
            ];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tos.exam-pdf', [
            'tos' => $tos,
            'examData' => $examData,
            // ?answers=1 for the teacher's answer-key copy; omitted (default)
            // renders a clean student-facing copy with no correct answers.
            'showAnswerKey' => $request->boolean('answers'),
        ])->setPaper('letter', 'portrait');

        $filename = $request->boolean('answers')
            ? 'Exam-Answer-Key-'.\Illuminate\Support\Str::slug($tos->course).'.pdf'
            : 'Exam-'.\Illuminate\Support\Str::slug($tos->course).'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Lightweight JSON list of past TOS records for the chat sidebar
     * history. No pagination for now — add ->limit(50) if this grows large.
     * Scoped to the authenticated user once auth is wired in; falls back to
     * showing everything (current behavior) if no one is logged in, so this
     * is safe to ship ahead of auth actually being enabled.
     */
    public function history()
    {
        $items = TableOfSpecification::query()
            ->when(Auth::check(), fn ($query) => $query->where('user_id', Auth::id()))
            ->latest()
            ->get(['id', 'course', 'total_items', 'created_at'])
            ->map(fn (TableOfSpecification $tos) => [
                'id' => $tos->uuid,
                'course' => $tos->course,
                'total_items' => $tos->total_items,
                'created_at' => $tos->created_at->format('M j, Y'),
            ]);

        return response()->json(['items' => $items]);
    }

    /**
     * Step 1: Teacher submits multiple lessons (each with its own title,
     * weight/emphasis, learning outcomes, and PDF). Runs the classifier
     * per-lesson, then builds a two-level balanced TOS: items across
     * lessons (by weight) THEN across Bloom's levels (within each lesson).
     */
    public function classifyAndBuildTos(Request $request)
    {
        $validated = $request->validate([
            'course' => 'required|string|max:255',
            'total_items' => 'required|integer|min:5|max:200',
            'lessons' => 'required|array|min:1|max:15',
            'lessons.*.title' => 'required|string|max:255',
            'lessons.*.weight' => 'required|numeric|min:0.1|max:100',
            'lessons.*.objectives_text' => 'required|string',
            // Accepts PDFs and photos of a printed lesson page (jpg/png),
            // e.g. scanned with a phone camera — PdfTextExtractorService
            // routes each by extension (OCR-only for images, embedded-text-
            // then-OCR-fallback for PDFs).
            'lessons.*.pdf' => 'required|file|mimes:pdf,jpg,jpeg,png|max:20480',
            // Optional per-course override of the default 20/20/20/15/15/10
            // Bloom's level split. Keys must match BloomClassifierService::LEVELS
            // (e.g. 'remembering', 'understanding', ...); values are percentages
            // and don't need to sum to exactly 100 — TosBuilderService normalizes
            // them. Omit entirely to keep using the hardcoded default split.
            'bloom_weights' => 'sometimes|array',
            'bloom_weights.*' => 'numeric|min:0|max:100',
        ]);
        // Note: Laravel automatically returns a 422 JSON error response for
        // ajax/`Accept: application/json` requests on validation failure —
        // no extra handling needed here for that case.

        $lessonWeights = [];
        foreach ($validated['lessons'] as $i => $lessonInput) {
            $lessonWeights[$i] = ['title' => $lessonInput['title'], 'weight' => (float) $lessonInput['weight']];
        }

        $customBloomWeights = $validated['bloom_weights'] ?? null;

        $allocation = $this->tosBuilder->buildAcrossLessons($lessonWeights, (int) $validated['total_items'], $customBloomWeights);

        $tos = DB::transaction(function () use ($request, $validated, $allocation) {
            $tos = TableOfSpecification::create([
                'course' => $validated['course'],
                'total_items' => $validated['total_items'],
                'distribution' => $allocation['aggregate_distribution'],
                'user_id' => Auth::id(),
            ]);

            foreach ($validated['lessons'] as $i => $lessonInput) {
                $path = $request->file("lessons.$i.pdf")->store('lesson_pdfs');

                $lesson = $tos->lessons()->create([
                    'title' => $lessonInput['title'],
                    'weight' => $lessonInput['weight'],
                    'pdf_path' => $path,
                    'item_quota' => $allocation['items_per_lesson'][$i],
                    'level_distribution' => $allocation['levels_per_lesson'][$i],
                    'sort_order' => $i,
                ]);

                $objectives = collect(explode("\n", $lessonInput['objectives_text']))
                    ->map(fn ($line) => trim($line))
                    ->filter()
                    ->values()
                    ->all();

                $classified = $this->classifier->classifyMany($objectives);

                foreach ($classified as $item) {
                    $lesson->objectives()->create([
                        'tos_id' => $tos->id,
                        'objective_text' => $item['objective'],
                        'bloom_level' => $item['level'],
                        'bloom_level_index' => $item['level_index'],
                        'confidence' => $item['confidence'],
                        'all_probabilities' => $item['all_probabilities'],
                    ]);
                }
            }

            return $tos;
        });

        $status = 'TOS built across '.count($validated['lessons']).' lesson(s). Review the balance, then generate the exam.';

        return $this->respond($request, $tos, $status);
    }

    /**
     * Step 2: Extract each lesson's PDF text, then generate exam questions
     * for ALL lessons CONCURRENTLY (one API call per lesson, fired via
     * Http::pool - see ExamGeneratorService), each scoped to that lesson's
     * own item quota and Bloom's-level breakdown.
     */
    public function generateExam(Request $request, TableOfSpecification $tos)
    {
        $this->authorizeTos($tos);

        // Generating for multiple lessons, each potentially retrying up to
        // 3x with backoff on rate-limit responses, can legitimately take
        // several minutes. The default 60s max_execution_time was killing
        // this mid-request and losing ALL lessons' results, not just the
        // one being retried. 300s gives real headroom; adjust if you add
        // more lessons per TOS or more retry attempts in ExamGeneratorService.
        set_time_limit(300);

        $tos->load('lessons');

        // Claim every lesson that isn't already completed or actively
        // being generated by another in-flight request. A lesson we fail
        // to claim is silently left out of this batch — it's either
        // already done or someone else's request already owns it, so
        // touching it here would reproduce the exact race this guards
        // against.
        $claimedLessons = $tos->lessons->filter(fn (Lesson $lesson) => $this->claimLessonForGeneration($lesson));

        if ($claimedLessons->isEmpty()) {
            return $this->respond($request, $tos, 'Exam already generated (or currently being generated) for every lesson in this TOS.');
        }

        $lessonJobs = [];
        $skipped = [];

        foreach ($claimedLessons as $lesson) {
            $absolutePath = Storage::path($lesson->pdf_path);
            $lessonText = $this->pdfExtractor->extractWithOcrFallback($absolutePath);
            $lessonText = $this->pdfExtractor->truncateForPrompt($lessonText, 10000);

            if (trim($lessonText) === '') {
                $skipped[] = $lesson->title;
                $lesson->update(['generation_status' => Lesson::STATUS_FAILED]);
                continue;
            }

            $lessonJobs[$lesson->id] = [
                'lesson_text' => $lessonText,
                'level_counts' => $lesson->level_distribution,
                'lesson_title' => $lesson->title,
            ];
        }

        if (empty($lessonJobs)) {
            return $this->respondError(
                $request,
                'Could not extract text from any lesson PDF, even after attempting OCR. Check that the PDF isn\'t corrupted and that Poppler/Tesseract are installed and reachable.'
            );
        }

        $results = $this->examGenerator->generateForLessons($lessonJobs, $tos->course);
        $errors = $this->persistLessonResults($tos, $results);

        $status = 'Exam generated across '.count($results).' lesson(s).';
        if (!empty($skipped)) {
            $status .= ' Skipped (no extractable text): '.implode(', ', $skipped).'. You can retry a lesson individually once its PDF is fixed.';
        }

        return $this->respond($request, $tos, $status, $errors);
    }

    /**
     * Retry generation for a SINGLE lesson — used when the initial batch
     * run skipped a lesson (unreadable PDF) or that lesson's API call
     * failed while its siblings succeeded. Leaves every other lesson's
     * questions untouched.
     */
    public function generateExamForLesson(Request $request, TableOfSpecification $tos, Lesson $lesson)
    {
        $this->authorizeTos($tos);

        set_time_limit(300);

        if ($lesson->tos_id !== $tos->id) {
            abort(404);
        }

        if (!$this->claimLessonForGeneration($lesson)) {
            return $this->respond($request, $tos, 'This lesson already has generated questions, or is currently being generated by another request.');
        }

        $absolutePath = Storage::path($lesson->pdf_path);
        $lessonText = $this->pdfExtractor->extractWithOcrFallback($absolutePath);
        $lessonText = $this->pdfExtractor->truncateForPrompt($lessonText, 10000);

        if (trim($lessonText) === '') {
            $lesson->update(['generation_status' => Lesson::STATUS_FAILED]);

            return $this->respondError(
                $request,
                "\"{$lesson->title}\": still no extractable text, even after attempting OCR. Check that the PDF isn't corrupted and that Poppler/Tesseract are installed and reachable."
            );
        }

        $lessonJobs = [
            $lesson->id => [
                'lesson_text' => $lessonText,
                'level_counts' => $lesson->level_distribution,
                'lesson_title' => $lesson->title,
            ],
        ];

        $results = $this->examGenerator->generateForLessons($lessonJobs, $tos->course);
        $errors = $this->persistLessonResults($tos, $results);

        if (!empty($errors)) {
            return $this->respond($request, $tos, null, $errors);
        }

        return $this->respond($request, $tos, "Questions generated for \"{$lesson->title}\".");
    }

    /**
     * Shared save step for both the full-batch and single-lesson generation
     * paths. Returns any per-lesson error messages encountered.
     *
     * @param array<int, array{questions: array, error: ?string}> $results
     * @return string[]
     */
    private function persistLessonResults(TableOfSpecification $tos, array $results): array
    {
        $errors = [];

        foreach ($results as $lessonId => $result) {
            $lesson = $tos->lessons->firstWhere('id', $lessonId) ?? Lesson::find($lessonId);

            if ($result['error']) {
                $errors[] = $result['error'];
                $lesson?->update(['generation_status' => Lesson::STATUS_FAILED]);
                continue;
            }

            if (!$lesson) {
                continue;
            }

            try {
                DB::transaction(function () use ($lesson, $result) {
                    $itemNumber = 1;

                    foreach ($result['questions'] as $q) {
                        $type = $q['question_type'] ?? 'multiple_choice';

                        $attrs = [
                            'tos_id' => $lesson->tos_id,
                            'item_number' => $itemNumber++,
                            'bloom_level' => $q['bloom_level'],
                            'question_type' => $type,
                            'question' => $q['question'],
                            'rationale' => $q['rationale'] ?? null,
                        ];

                        switch ($type) {
                            case 'modified_true_false':
                                $attrs['is_true'] = (bool) ($q['is_true'] ?? false);
                                $attrs['correction'] = $q['is_true'] ? null : ($q['correction'] ?? null);
                                break;
                            case 'enumeration':
                                $attrs['accepted_answers'] = $q['accepted_answers'] ?? [];
                                break;
                            case 'multiple_choice':
                            default:
                                $attrs['options'] = $q['options'] ?? null;
                                $attrs['correct_answer'] = $q['correct_answer'] ?? null;
                                break;
                        }

                        $lesson->examQuestions()->create($attrs);
                    }
                });

                $lesson->update(['generation_status' => Lesson::STATUS_COMPLETED]);
            } catch (\Throwable $e) {
                // Whole lesson's batch rolls back on any failed insert —
                // surface it the same way an upstream generation error
                // would be, rather than leaving partially-saved questions.
                $errors[] = "\"{$lesson->title}\": failed to save generated questions ({$e->getMessage()}).";
                $lesson->update(['generation_status' => Lesson::STATUS_FAILED]);
            }
        }

        return $errors;
    }

    /**
     * Builds the common response: JSON + rendered results fragment for
     * fetch()-driven requests (the normal path from the single-page view),
     * or a classic redirect for any client that isn't sending our AJAX
     * headers (progressive-enhancement fallback, e.g. JS disabled).
     */
    private function respond(Request $request, TableOfSpecification $tos, ?string $status = null, array $errors = []): mixed
    {
        $tos->load('lessons.objectives', 'lessons.examQuestions');
        $itemPlacement = $this->itemPlacement->group($tos);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('tos._results', ['tos' => $tos, 'itemPlacement' => $itemPlacement])->render(),
                'tos_id' => $tos->uuid,
                'status' => $status,
                'errors' => $errors,
            ], empty($errors) ? 200 : 207); // 207: partial success, some lessons still errored
        }

        $redirect = redirect()->route('tos.show', $tos);
        if ($status) {
            $redirect->with('status', $status);
        }
        if (!empty($errors)) {
            $redirect->withErrors($errors);
        }

        return $redirect;
    }

    private function respondError(Request $request, string $message): mixed
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['errors' => ['lesson_pdf' => $message]], 422);
        }

        return back()->withErrors(['lesson_pdf' => $message]);
    }
}