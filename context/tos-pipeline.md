# TOS pipeline (`TosController` + allocation services)

## Related File Index

| File Location | Function |
|---|---|
| `app/Http/Controllers/TosController.php` | Request validation, orchestration, persistence, JSON/redirect responses |
| `app/Services/Bloom/TosBuilderService.php` | Two-level TOS allocation (lessons → Bloom levels) |
| `app/Services/Bloom/ApportionmentService.php` | Largest-remainder rounding so counts sum exactly |
| `routes/web.php` | Endpoint definitions; static routes must stay above `/{tos}` wildcard |

## Flow (two requests, not one)

1. `POST /tos` → `classifyAndBuildTos`: validates `course`, `total_items` (5–200),
   `lessons` array (1–15, each: `title`, `weight` 0.1–100, `objectives_text`, `pdf`
   ≤20MB). Builds TOS allocation FIRST, then stores PDFs to `lesson_pdfs/`,
   classifies objectives per lesson, all inside one DB transaction.
2. `POST /tos/{tos}/generate-exam` → `generateExam`: extracts each PDF's text
   (truncated to 10 000 chars via `truncateForPrompt`), skips empty-text lessons,
   fires one Gemini call per lesson concurrently, persists per-lesson results.
   Guard: refuses if exam already exists (`examQuestions()->exists()`).
3. `POST /tos/{tos}/lessons/{lesson}/generate-exam` → `generateExamForLesson`:
   single-lesson retry. Aborts 404 if `lesson.tos_id != tos.id`; no-ops if that
   lesson already has questions. Other lessons untouched.

## Two-level allocation (`TosBuilderService::buildAcrossLessons`)

- Level 1: `total_items` → per-lesson quota, weighted by teacher `weight`
  (class hours/emphasis, NOT page count).
- Level 2: each lesson's quota → 6 Bloom levels using default weights
  `20/20/20/15/15/10` (Remembering…Creating, must sum to 1.0). No per-lesson
  Bloom override exists — global weights for all lessons.
- Both levels use `ApportionmentService::apportion` (largest remainder), so
  counts always sum exactly. Independent per-lesson rounding can drift the
  aggregate Bloom balance by ~1 item — expected, not a bug.

## Responses (fetch-driven, see `context/frontend.md`)

- `respond()`: JSON `{html, tos_id, status, errors}` + rendered `tos._results`
  fragment for AJAX; classic redirect fallback otherwise.
- Status codes: `200` clean, **`207` partial success** (some lessons errored —
  read `errors[]`), `422` validation or unreadable-PDF failure.
- `show()` with `Accept: application/json`/AJAX returns `{html, tos_id, course, has_exam}`.
- `history()` returns unscoped `[{id, course, total_items, created_at}]`, no pagination.
