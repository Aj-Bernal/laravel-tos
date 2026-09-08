# Data model + migrations

## Related File Index

| File Location | Function |
|---|---|
| `app/Models/TableOfSpecification.php` | TOS record; `hasMany` lessons/objectives/examQuestions |
| `app/Models/Lesson.php` | One row per uploaded lesson PDF; quota + level distribution |
| `app/Models/LearningObjective.php` | Classified objective; belongs to TOS + lesson |
| `app/Models/ExamQuestion.php` | Generated question — all three types (fillable + casts complete, fixed 2026-09-08) |
| `database/migrations/2026_07_08_000001_create_tos_exam_tables.php` | Creates the 4 TOS tables (`options`/`correct_answer` nullable from day one) |
| `database/migrations/2026_07_13_000000_add_question_type_fields_to_exam_questions_table.php` | Adds `question_type`/`is_true`/`correction`/`accepted_answers`; add-columns-only, no dbal required |

## Tables and relations

- `table_of_specifications` (`TableOfSpecification`): `user_id?`, `course`,
  `total_items`, `distribution` (json, aggregate per-level `{weight, item_count}`).
  `hasMany lessons (ordered by sort_order)`, `objectives`, `examQuestions`.
- `lessons` (`Lesson`): `tos_id` cascade, `title`, `weight` (float, teacher emphasis),
  `pdf_path`, `item_quota`, `level_distribution` (json cast, nullable),
  `sort_order`. `hasMany objectives`, `examQuestions`.
- `learning_objectives` (`LearningObjective`): `tos_id` + `lesson_id` (both cascade),
  `objective_text`, `bloom_level`, `bloom_level_index`, `confidence`,
  `all_probabilities` (json cast).
- `exam_questions` (`ExamQuestion`): `tos_id` + `lesson_id` (cascade), `bloom_level`,
  `question_type` (default `multiple_choice`, added by 2nd migration),
  `question`, `options` (json, should be nullable), `correct_answer` (should be nullable),
  `is_true?`, `correction?`, `accepted_answers?` (json), `rationale?`.

## Gotchas

1. **`ExamQuestion::$fillable` fixed 2026-09-08** — now carries `question_type`,
   `is_true`, `correction`, `accepted_answers` with casts
   (`options`/`accepted_answers` → `array`, `is_true` → `boolean`).
   Locked by `tests/Feature/ExamQuestionTypePersistenceTest.php` — keep it green.
2. **No `doctrine/dbal` needed (fixed 2026-09-08)** — the 2nd migration is
   add-columns-only; `options`/`correct_answer` are nullable from the base
   migration. Do NOT add `->change()` calls. Stale/partially-migrated dev DBs:
   `migrate:fresh`.
3. **Schema break**: `lesson_id` is now required on objectives/questions.
   Old DBs must `migrate:fresh` (or drop the 4 TOS tables manually, then `migrate`).
4. `user_id` is nullable with `nullOnDelete`; no auth scoping anywhere (see history note
   in `TosController` — add `where('user_id', auth()->id())` when auth lands).
5. `distribution` / `level_distribution` store computed quotas, not live queries —
   rebuilding allocation requires re-running `TosBuilderService`, not just re-reading rows.
