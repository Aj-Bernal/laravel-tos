# AGENTS.md — laravel-tos (Automated TOS & Exam Generator)

Laravel 12 + PHP 8.2 app. Teacher uploads 1–15 lesson PDFs → per-lesson Bloom classification →
two-level TOS allocation → concurrent Gemini exam generation. Single-page Blade UI, no auth.

## System rule

Before implementing any change, read the relevant file(s) under `context/` first —
they hold verified, non-obvious constraints (route ordering, stale model fields,
API key source, retry behavior) that the code alone won't warn you about.

## Domain context (read before touching that area)

- `context/tos-pipeline.md` — controller flow, two-level allocation, response codes
- `context/bloom-classifier.md` — local ML model, training, retraining
- `context/exam-generation.md` — Gemini API, `Http::pool`, retries, question-type schemas
- `context/data-model.md` — tables, relations, migration gotchas, known model bug
- `context/frontend.md` — single-page Blade/fetch UI, route-ordering rule, Vite/Tailwind v4

## Setup (verified from `composer.json`, `phpunit.xml`, code)

```bash
composer install
cp .env.example .env        # then ADD GEMINI_API_KEY=... (missing from .env.example, required)
php artisan key:generate
php artisan migrate
php artisan bloom:train     # fallback small model; canonical is sklearn trainer below
python scripts/bloom/build_samples_csv.py && python scripts/bloom/train_bloom_sklearn.py  # canonical: builds model JSON (see context/bloom-classifier.md)
npm install; npm run build
```

- DB default is sqlite (`DB_CONNECTION=sqlite` in `.env.example`). Tests override to `:memory:` in `phpunit.xml`.
- Dev: `composer dev` (serve + queue:listen + pail + vite via concurrently).
- No auth wired in — `user_id` on TOS table is nullable, history endpoint is unscoped.

## Commands

```bash
php artisan test                              # full suite (runs config:clear first via composer test)
php artisan test --filter=Name                # single test
./vendor/bin/pint --test                      # lint check (Laravel Pint)
php artisan bloom:train --epochs=300 --lr=0.5 # retrain classifier after editing BloomTrainingData
```

## Gotchas (all verified in code)

1. **Route order matters** (`routes/web.php`): `/tos/{tos}` wildcard must stay AFTER `/tos/create`, `/tos/chat`, `/tos/history` — Laravel matches top-to-bottom.
2. **README is stale on API keys**: code uses `GEMINI_API_KEY` (`ExamGeneratorService::MODEL = gemini-2.5-flash-lite`), README still says `ANTHROPIC_API_KEY`. Trust the code.
3. **No `doctrine/dbal` needed** (fixed 2026-09-08): `2026_07_13_...` is add-columns-only, and `options`/`correct_answer` are nullable from the base `2026_07_08_...` migration. Do NOT add `->change()` calls — they would reintroduce the dbal requirement. On a stale/partially-migrated dev DB, run `migrate:fresh`.
4. **`ExamQuestion` `$fillable` fixed** (2026-09-08): carries `question_type`, `is_true`, `correction`, `accepted_answers` with casts (`options`/`accepted_answers` → `array`, `is_true` → `boolean`). Locked by `tests/Feature/ExamQuestionTypePersistenceTest.php` — keep it green.
5. **Exam generation is slow by design**: `set_time_limit(300)` in controller; pooled Gemini calls (120s timeout each) + sequential 429/503 retry with backoff. Expect 10–30s+ per batch. Partial success returns HTTP 207 — check `errors` array, don't treat as full failure.
6. **Scanned PDFs yield empty text** (`PdfTextExtractorService` = `smalot/pdfparser` only, no OCR): controller skips them and reports per-lesson; single-lesson retry route exists at `POST /tos/{tos}/lessons/{lesson}/generate-exam`.
7. **Schema changed mid-project**: `learning_objectives`/`exam_questions` now require `lesson_id`. On an old DB, `migrate:fresh` (or manually drop the 4 TOS tables then `migrate`).

## STATE.md Planning & Handoff Rules

1. At planning stage, create the task plan inside `STATE.md` (project root).
2. After every task completion, update the task list in `STATE.md`.
3. Use `[ ]` for open tasks and `[x]` for completed tasks as checkmarks.
4. At the top of each `## <Task / Feature Implementation name>` header, add a
   date (`YYYY-MM-DD`).
5. `STATE.md` is the cross-session context handoff — any new session resumes
   by reading `STATE.md` first.
