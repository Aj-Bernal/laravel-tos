# STATE.md — Cross-Session Context Handoff & Task Tracker

> This file is the project's cross-session context handoff. Any agent or
> developer resuming work in a new session starts here: current goal, open
> task plans, and per-task completion state.

## 2026-09-19 — Python sklearn Bloom Trainer (PYTHON-SKLEARN-PLAN.md)

Decisions (user): PH upweight 2x, Kaggle rewritten+included, CogBench silver excluded from core.
Deviations: EDM 21k not publicly released (paper used private scraper; SteveLEEEEE unresolvable) — substituted with Kaggle 8.7k + itsskofficial screened OUT (label collapse: Eval 19 / Synth 2); frankwong2001 has no blooms set (only ssf-*) — jsano PH part kept. Active model path is `storage/app/private/ml/` (Laravel 12), not `storage/app/ml/`.
Result: baseline PHP kept ACTIVE (holdout v2 98.3%); best sklearn candidate `data/bloom/candidate_C05_aw8.json` (holdout 91.7%, CogBench-gold OOD 81.6% vs baseline 51.8%). Cutover = open user decision.

- [x] 1. Freeze baseline: `bloom:train` (356 train/63 val, vocab 799) + `data/bloom/baseline_v2.txt` (98.3%).
- [x] 2. `scripts/bloom/build_samples_csv.py` → `data/bloom/samples.csv` (9,349 rows; Kaggle 8,767 + ph_k12 210 + anchor 419; 47 dupes dropped, 0 holdout leaks).
- [x] 3. `scripts/bloom/train_bloom_sklearn.py` + `requirements-ml.txt` (parity analyzer, 80/20+70/30+5fold, .tex/.png/.csv artifacts, --grid-search, --anchor-weight).
- [x] 4. Train C=1.0 (80/20 macro 0.79, CV 0.801) → C=0.5/anchor-x4 (macro 0.84, CV 0.831, gap 0.11) → C=0.5/anchor-x8 (macro ~0.84, CV 0.855, holdout 91.7%). Full 12-config grid deferred (each fit minutes; flag works).
- [x] 5. Verify: `php artisan test` 22 passed/1 failed (pre-existing ExampleTest 404, unchanged); PHP smoke 4/4 incl. Tagalog; baseline restored as active with `.bak` rollback in place.
- [x] 6. CUTOVER (user call 2026-09-19): CUT OVER to `candidate_C05_aw8.json` as active model. Baseline kept at `storage/app/private/ml/bloom_model.json.bak.php-baseline-20260919`. Post-cutover: holdout v2 91.7%, Bloom tests 16/16, PHP 1-per-level spot-check 6/6. Rollback = copy backup over active file.

## 2026-09-08 — Three Question Types Persistence (verdict item d)

Context: verdict (d) claimed `ExamQuestion::$fillable` lacked `question_type`,
`is_true`, `correction`, `accepted_answers`, so all rows saved as
`multiple_choice`. Re-verified 2026-09-08 against the code: the model layer
fix appears to have LANDED already (`app/Models/ExamQuestion.php:13-32` has
all four fields in `$fillable` plus `options`/`is_true`/`accepted_answers`
casts), and `TosController::persistLessonResults()` (`app/Http/Controllers/TosController.php:496-560`)
builds per-type `$attrs` correctly, and `_results.blade.php:99-160` renders all
branches. Remaining unverified risk is the PERSISTENCE layer: migration
`database/migrations/2026_07_13_000000_add_question_type_fields_to_exam_questions_table.php`
uses `->change()` (needs `doctrine/dbal`, which is NOT in `composer.json`
`require`), so it is unclear the columns exist in any real database. No code
changes made in this session — plan only.

Work branch: `fix/question-type-persistence` — MERGED into `main` 2026-09-08
(commit `79ad828`; post-merge suite still 22/1 with the same pre-existing
`ExampleTest` failure). Merge note: `origin/main` had moved (PR #1,
`context/` + full `AGENTS.md`); resolved an add/add conflict on `AGENTS.md`
by keeping origin's file, appending the STATE.md rules section, and flipping
Gotchas #3/#4 plus `context/data-model.md` to the fixed state. Not pushed —
`git push` still pending whenever you want it upstream.

- [x] 1. Verify migration status — done STATICALLY (no PHP runtime in this environment, so `migrate:status` could not run). Confirmed: base migration (`2026_07_08`) creates `options`/`correct_answer` as NOT NULL, and `2026_07_13` alters them via `->change()` with no `doctrine/dbal` in `composer.json` — so the chain is broken on ANY database, fresh or existing. Live `migrate:status` folded into item 7.
- [x] 2. Decide dbal strategy: NO-DBAL route chosen (no new dependency, works uniformly on sqlite/mysql/pgsql). Implemented: base migration now declares `options`/`correct_answer` `->nullable()` at creation; `2026_07_13` reduced to add-columns-only, `->change()` block deleted, docblock rewritten. Anyone holding a partially-migrated dev DB should `migrate:fresh` (no production DB evidence in repo).
- [x] 3. Run the migration on a clean database — DONE 2026-09-08: `migrate:fresh` green, all 8 migrations including the rewritten `2026_07_13` (no dbal, no error). Verdict-(d) persistence break is fixed at the schema layer.
- [x] 4. Round-trip smoke test: regression test WRITTEN (`tests/Feature/ExamQuestionTypePersistenceTest.php`, 5 tests: MC options/answer, MTF false+correction with NULL options, MTF true+null correction, enumeration answers, type-column default) using the exact attr shapes `persistLessonResults()` builds. EXECUTED 2026-09-08: 5 passed, 16 assertions.
- [x] 5. Rendering check (static): `_results.blade.php:99-160` confirmed — per-type branches for multiple_choice / modified_true_false / enumeration (+ algorithm_trace), no everything-falls-back-to-MC path. Live visual check pending — item 7.
- [x] 6. Clarify `algorithm_trace`: DECIDED keep-as-reserved, no code change. View (`_results:153-160`) and persist (`TosController:535-536`) handle it, but `TYPE_MIX_LOWER/HIGHER` never assign it and the prompt schema only describes 3 types — harmless dead branch for future use. Removing it would touch persist+view for zero user-visible gain.
- [x] 7. Runtime verification — DONE 2026-09-08 on this machine: `composer install` (123 packages), `.env` created + key generated, `migrate:fresh` 8/8 green, targeted test 5/5 pass, FULL SUITE 22 passed / 1 failed — the single failure is the stock `ExampleTest` (`GET /` → 404) and is PRE-EXISTING: `routes/web.php` defines no `/` route (app starts at `/tos/...`), unrelated to this fix. Follow-up (out of scope): redirect `/` → `/tos/create` or update the example test. `npm install` + `npm run build` also green (55 modules). Verdict (d) can be marked FIXED once this branch is merged.
  - Environment audit 2026-09-08 (this machine): PHP 8.2.12 EXISTS via XAMPP (`C:\xampp\php\php.exe`, not on PATH) with all required extensions (mbstring/openssl/PDO/pdo_sqlite/tokenizer/xml/ctype/fileinfo/curl/bcmath/json). Node 24.15 present. RESOLVED same day: Composer installed via official phar (`C:\xampp\php\composer.phar`, winget msstore source was broken); enabled `extension=zip` in `C:\xampp\php\php.ini` (was blocking dist downloads); `vendor/` installed; `.env` created + APP_KEY set (note: `GEMINI_API_KEY` still unset — required for real generation calls); sqlite DB migrated; `node_modules/` installed (via `npm.cmd` — plain `npm.ps1` is blocked by this shell's execution policy) and vite build green.
