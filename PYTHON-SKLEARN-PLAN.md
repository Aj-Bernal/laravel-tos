# Python scikit-learn Bloom Trainer — Implementation Plan

> Goal: replace PHP-only training (`php artisan bloom:train`) with an offline
> Python scikit-learn trainer for scale (EDM 18k + PH K-12 + ICT/Tagalog anchor),
> while keeping PHP inference (`BloomClassifierService::classify`) unchanged.
> No Python runtime required on the web server.

## 1. Architecture decision

```
BEFORE: BloomTrainingData.php -> TrainBloomModel.php (PHP GD, 300 epochs) -> ml/bloom_model.json -> BloomClassifierService (PHP infer)
AFTER:  datasets (csv) -> train_bloom_sklearn.py (offline, any machine) -> ml/bloom_model.json (SAME SHAPE) -> BloomClassifierService (PHP infer, untouched)
```

- Train in Python. Infer in PHP. JSON is the contract.
- `php artisan bloom:train` kept as fallback for small edits, but documented
  as non-canonical once row count exceeds ~2k.
- No HTTP sidecar, no pickle/joblib on server, no new production dependency.

## 2. JSON contract (must not break)

`storage/app/ml/bloom_model.json`:

```json
{
  "vocab": ["...", "..."],
  "weights": [[...], x6],
  "bias": [...6...],
  "levels": ["Remembering","Understanding","Applying","Analyzing","Evaluating","Creating"],
  "trained_at": "iso8601",
  "sample_count": 1234
}
```

- `vocab`: sorted list, min doc-freq filtered (2 for small data, 5 for 18k+).
- `weights`: `[6][V]` == sklearn `coef_.tolist()`.
- `bias`: `[6]` == sklearn `intercept_.tolist()`.
- `levels`: fixed order matching `BloomClassifierService::LEVELS`.
- PHP loader (`BloomClassifierService.php:32-47`) reads this verbatim — any
  extra keys are ignored, missing keys throw.

## 3. Parity requirements (weights align only if these match)

1. Tokenizer == `BloomClassifierService.php:54-61`:
   - `lower()`, `re.sub(r'[^a-z\s]',' ', text)`, split on `\s+`, drop `len <= 2`.
2. N-grams == `::ngrams():74-84`:
   - unigrams + bigrams joined with underscore `word1_word2`.
   - In sklearn use `CountVectorizer(analyzer=custom_fn)`, NOT `ngram_range`
     (which joins with space and breaks vocab lookup).
3. Features: raw counts (not binary, not tf-idf). `vec[idx] += 1.0`.
4. Labels: ints `0-5` in fixed order. Sklearn sorts classes — pass explicit
   `labels` mapping and verify `clf.classes_ == [0,1,2,3,4,5]`.
5. Regularization: PHP `lambda=0.001` has no exact sklearn equivalent.
   Tune `C` on validation instead of trying to convert (`C ~ 1/(lambda*N)` as starting point only).

Reference parity harness: `evaluate_holdout_v2.py:76-105` already mirrors
PHP classify in Python. Every exported model must pass it before deploy.

## 4. Data pipeline

### 4.1 Sources and treatment

| Source | Rows | Treatment |
|---|---|---|
| EDM2022CLO (SteveLEEEEE) | 21,380 -> ~18.7k single-label | Base. Drop ~12% multi-label rows (or move to separate eval set). Map labels to 0-5. |
| frankwong2001 + jsano/blooms_taxonomy PH K-12 | 210 + 210 descriptions | Most domain-relevant. Upweight 2-3x in training, plus use as DepEd validation slice. |
| Kaggle vijaydevane 8,767 questions | 8,767 | Questions, not objectives. Rewrite via template before ingest: `"Students will be able to " + lcfirst(rstrip(question,"?")) + "."` Verify BT1-BT6 mapping. Dedupe vs EDM. |
| mouryat9/CogBench | 739 gold usable, rest silver 82% | Gold for eval/augment only. Do NOT train core on silver. |
| Verb lists | — | Audit only. Check vocab gaps, then author full sentences around missing verbs. Never train on bare verbs. |
| Current `BloomTrainingData.php` ICT/Tagalog anchor | ~400 | Keep 100-200 rows (ICT + Tagalog + DepEd phrasing) so EDM Australian-uni English doesn't wash out `OSI model / malware / mag-aaral` weights. |

### 4.2 Converter

New script `scripts/bloom/build_samples_csv.py`:

- Input: raw EDM csv/json, Kaggle csv, HF downloads, plus export of current PHP samples.
- Output: `data/bloom/samples.csv` with columns `text,label` where `label in 0..5`.
- Steps: normalize whitespace, map labels, drop multi-label, apply question->objective template for Kaggle rows, dedupe exact text, tag `source` column for weighting, stratified report of counts per level.
- Never include `evaluate_holdout_v2.py:8-74` (60 sentences) in training output.

### 4.3 Splits

- Primary: stratified `80/20` and `70/30` holdouts (to match the `80:20 / 70:30`
  blocks in the exemplar screenshot). Run both and report both — don't pick one.
- PHP-parity split: `85/15` (`--val-split=0.15`, `TrainBloomModel.php:22`) for
  direct comparison to `php artisan bloom:train`.
- Cross-validation: `StratifiedKFold(5, shuffle=True, seed=42)` for the Grid
  Search path. Use `TimeSeriesSplit (TSCV)` only if data is chronological
  (EDM/PH are not); default to `StratifiedKFold`.
- Fixed `--seed=42` for reproducibility.
- Held-out v2 (`evaluate_holdout_v2.py:8-74`, 60 sentences) + PH-420 slice are
  test-only, never fit.

## 5. Trainer spec

New file `scripts/bloom/train_bloom_sklearn.py`:

```
python scripts/bloom/train_bloom_sklearn.py \
  --input data/bloom/samples.csv \
  --out storage/app/ml/bloom_model.json \
  --min-df 5 --C 1.0 --max-iter 1000 \
  --val-split 0.2 --seed 42 --class-weight balanced
```

- Vectorizer: custom analyzer (Sec. 3), `min_df` configurable.
- Model: `LogisticRegression(multi_class='multinomial', solver='lbfgs', C, max_iter)`.
  Never `binary:logistic` — EDM screenshot's `objective=binary:logistic` is for
  `Normal/Abnormal` binary; this task is 6-class multinomial.
- Overfit guard: warn if `train - val > 0.15` (same rule as `TrainBloomModel.php:202-209`).
- Requirements: `requirements-ml.txt` with `scikit-learn, pandas, numpy, matplotlib, seaborn` — dev/training machine only, NOT added to composer or production image.

### 5.1 Evaluation output contract (matches exemplar screenshot + how papers present it)

Every training run MUST emit the same block papers/examiners expect —
`sklearn.metrics.classification_report` + `confusion_matrix` — for each split.
This mirrors the screenshot's left panel (`Train-test split of 80:20 / 70:30`
+ `precision recall f1-score support` + `macro avg / weighted avg` + `Confusion matrix`).

Console template (6 rows instead of the screenshot's 2 — same shape):

```
# Train-test split of 80:20
# Model: Logistic Regression (multinomial, C=1.0, min_df=5)
# Classification report                precision  recall  f1-score  support
Remembering                0.82     0.90     0.86     3120
Understanding              0.71     0.65     0.68     3105
Applying                   0.74     0.78     0.76     3080
Analyzing                  0.68     0.62     0.65     2950
Evaluating                 0.69     0.71     0.70     3010
Creating                   0.78     0.75     0.76     3115
accuracy                                        0.72    18380
macro avg                  0.74     0.73     0.73    18380
weighted avg               0.74     0.72     0.73    18380

# Confusion matrix (rows=actual, cols=predicted, same as screenshot but 6x6)
[[2810  120 ...] ...]
# Per-class rates (derived): TN/FP/FN per level available via one-vs-rest if needed

# Train-test split of 70:30
# ...identical block with 70/30 numbers...

# 5-Fold CV (macro F1)
Fold 1: 0.71  Fold 2: 0.73 ...  Mean: 0.72 +/- 0.02
```

File artifacts (for thesis appendix — papers always attach these):

- `data/bloom/metrics_<run>.json`: `{C, min_df, seed, vocab_size, splits:{80_20:{accuracy, macro_f1, weighted_f1, per_level:{...}}}, cv:{mean, std}}`
- `data/bloom/classification_report_<split>.csv` + `.tex` (LaTeX table row for direct thesis inclusion)
- `data/bloom/confusion_matrix_<split>.png` (seaborn heatmap, annotated counts) + `.csv` (raw 6x6)
- `data/bloom/baseline_v2.txt`: frozen baseline from current `bloom_model.json` for delta table

Code sketch inside the trainer (do not shell out):

```python
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score

report_dict = classification_report(y_test, y_pred, target_names=LEVELS, output_dict=True)
print(classification_report(y_test, y_pred, target_names=LEVELS, digits=2))
print(confusion_matrix(y_test, y_pred))
# also save report_dict to metrics JSON + heatmap via seaborn.heatmap(cm, annot=True)
```

### 5.2 Hyperparameter search (matches screenshot right panel — `Grid Search TSCV`)

Add optional flag:

```
python scripts/bloom/train_bloom_sklearn.py --grid-search --cv 5
```

- Grid: `C in {0.5, 1.0, 2.0, 4.0}`, `min_df in {2, 5}`, `class_weight in {balanced, None}` — Logistic Regression only; do not carry over XGB params (`learning_rate, max_depth, n_estimators, scale_pos_weight`) from the screenshot's `clf_xgb_tscv / clf_xgb_hba` blocks.
- CV: `StratifiedKFold(5, shuffle=True, random_state=42)` (replace `TSCV 60/40` unless data is time-ordered). Scoring `f1_macro` (not `aucpr` — binary metric in screenshot). Report `best_params_`, `cv_results_` mean/std.
- Pick winner by highest `cv f1_macro`, then confirm on held-out `80/20` and on `evaluate_holdout_v2.py` — never pick on test set alone.

### 5.3 How this is usually presented in papers (thesis template)

Replicate the Li et al. EDM22 / XGB-HBA paper layout in your thesis Chapter 4:

1. Dataset table: rows per source/level before/after filtering (EDM single-label filter, PH upweight, Kaggle rewrite). Cite `Kap=0.80` for EDM, note domain shift `AU uni vs PH K-12`.
2. Preprocessing + features: one paragraph citing `BloomClassifierService.php:54-84` (lowercase, `[^a-z]` strip, `len>2`, `unigram + word1_word2` with `_`, `min_df`, raw counts). State parity harness `evaluate_holdout_v2.py:76-105`.
3. Experimental setup: splits `80/20 + 70/30 + 5-fold CV, seed=42`, model `multinomial Logistic Regression (lbfgs)`, grid per Sec. 5.2.
4. Results table: `classification_report` table (precision/recall/F1/support + `accuracy/macro/weighted`) for each split — paste the `.tex` artifact directly. Baseline `PHP GD` row vs `sklearn` row.
5. Figure: `confusion_matrix.png` heatmap (6x6) with caption per-class confusion (e.g. `Analyzing <-> Evaluating`). Papers always show this figure alongside the table.
6. Discussion: delta in `macro F1` (not just accuracy — handles class imbalance), per-level recall drops >5pts flagged, overfit check `train-val>0.15`.

Export: the trainer's `.tex` + `.png` are thesis-ready; no manual retyping of the report.

## 6. Evaluation and acceptance

1. `python evaluate_holdout_v2.py storage/app/ml/bloom_model.json` — overall acc + per-class table on the frozen 60-sentence holdout. Baseline (current PHP model) recorded first (`data/bloom/baseline_v2.txt`); new model must beat or tie `macro F1`, with no single level recall dropping >5pts.
2. Trainer self-report: both `80/20` and `70/30` `classification_report` + `5-fold CV mean+/-std` (Sec. 5.1) must be present in `metrics_<run>.json` and attached as `confusion_matrix_<split>.png`. Thesis reviewer checks these artifacts, not just console.
3. `php artisan test --filter=Bloom` (or full suite) — inference shape unchanged, no PHP changes to break.
4. Manual spot-check in UI: paste 1 objective per level on `/tos/create`, confirm predicted levels + confidence render.
5. Scale check: training on full 18k+ completes in <5 min on dev machine, output JSON loads in PHP without memory error (`BloomClassifierService::loadModel`).
6. Presentation check: `classification_report_<split>.tex` compiles in thesis without hand-editing; confusion heatmap is 6x6, labeled `Remembering..Creating` on both axes, matching paper layout per Sec. 5.3.

## 7. Integration steps (ordered)

- [ ] 1. Freeze baseline: run `evaluate_holdout_v2.py` on current `bloom_model.json`, save output to `data/bloom/baseline_v2.txt`.
- [ ] 2. Add `scripts/bloom/build_samples_csv.py` + build `data/bloom/samples.csv` + per-source count report.
- [ ] 3. Add `scripts/bloom/train_bloom_sklearn.py` + `requirements-ml.txt`.
- [ ] 4. Train: full mix with `--min-df 5 --C 1.0`, capture both `80/20` + `70/30` reports + `5-fold CV` (Sec. 5.1) + holdout v2 scores.
- [ ] 5. Tune once if needed: `--grid-search` over `C in {0.5, 1.0, 2.0, 4.0}`, `min_df in {2,5}`; pick best `cv f1_macro`, confirm on both holdout splits and on holdout v2 (never pick on test alone).
- [ ] 6. Export JSON to `storage/app/ml/bloom_model.json` (back up old file first — single active model, retrain overwrites per `project-overview.md`). Also freeze `classification_report_<split>.tex/csv` + `confusion_matrix_<split>.png` for thesis Sec. 5.3.
- [ ] 7. Verify: `evaluate_holdout_v2.py` + `php artisan test` + UI spot-check.
- [ ] 8. Docs: update `AGENTS.md` setup line (`bloom:train` fallback vs sklearn canonical) and `context/bloom-classifier.md` with new trainer path. Do not commit the JSON model file (still generated).

## 8. Rollback

- Keep backup `storage/app/ml/bloom_model.json.bak.<date>` before step 6.
- Rollback = restore backup file, no code deploy needed (inference reads file at request time).
- If sklearn output ever fails to load, `php artisan bloom:train` reproduces a working small model from `BloomTrainingData.php`.

## 9. Risks

- Tokenizer drift (space vs underscore bigrams, `<=2` char filter, `[^a-z]` stripping of Tagalog `'`): mitigated by shared analyzer + holdout parity test.
- Domain shift (EDM uni English drowning DepEd/ICT/Tagalog): mitigated by anchor rows + PH upweight + per-slice eval.
- Label noise (silver CogBench, question->objective templating): mitigated by excluding silver from core, manual review of template output sample.
- Single-model overwrite (no versioning): mitigated by backup + `metrics.json` per run; future work is model version table if A/B needed.
