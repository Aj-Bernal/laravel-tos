# Bloom classifier (local ML, no external service)

## Related File Index

| File Location | Function |
|---|---|
| `app/Services/Bloom/BloomClassifierService.php` | Request-time inference only (`classify` / `classifyMany`) |
| `app/Services/Bloom/BloomTrainingData.php` | Labeled `[text, classIndex]` samples — edit to improve accuracy, then retrain |
| `app/Console/Commands/TrainBloomModel.php` | `php artisan bloom:train` — builds vocab, trains, writes model JSON |
| `storage/app/private/ml/bloom_model.json` | Trained weights (generated, NOT committed — fresh clones must train). NOTE: Laravel 12 `Storage::put('ml/...')` resolves to `storage/app/private/ml/`, not `storage/app/ml/` as older docs say. |
| `scripts/bloom/build_samples_csv.py` | Canonical dataset builder → `data/bloom/samples.csv` (`text,label,source`). Kaggle-8.7k rewritten + PH-K12 (`ph_k12`) + PHP anchor. Never includes holdout v2. |
| `scripts/bloom/train_bloom_sklearn.py` | Canonical trainer (multinomial LR, PHP-parity analyzer). Emits 80/20 + 70/30 reports, 5-fold CV, `.tex`/`.png`/`.csv` thesis artifacts, same-shape JSON. `--grid-search` supported. `requirements-ml.txt` is training-machine-only. |
| `data/bloom/` | `samples.csv`, `metrics_<run>.json`, `classification_report_<split>.{csv,tex}`, `confusion_matrix_<split>.{csv,png}`, `baseline_v2.txt`, `candidate_*.json`. |

Pure-PHP multinomial (softmax) logistic regression over bag-of-words. No ML
library, runs on XAMPP. Levels (fixed order): Remembering, Understanding,
Applying, Analyzing, Evaluating, Creating.

Tokenizer: lowercase, strip non-`a-z`, drop tokens ≤2 chars.

## Rules agents must follow

- **Model file is NOT committed** (generated into `storage/`). Fresh clones MUST run
  `php artisan bloom:train` or every `classify*` call throws `RuntimeException`.
- **Retrain after editing `BloomTrainingData`** — otherwise the JSON weights are stale.
- **2026-09-19 sklearn results**: baseline PHP (419 rows) held 98.3% on in-distribution holdout v2 but only 51.8% on the 739-question CogBench-gold OOD slice. ACTIVE model is now the sklearn candidate (`C=0.5`, anchor x8, 12.5k rows: 80/20 macro F1 0.84, CV 0.855±0.003; holdout v2 91.7%, OOD 81.6%). PHP baseline kept as `storage/app/private/ml/bloom_model.json.bak.php-baseline-20260919` — rollback is a file copy.
- Inference expects the exact JSON shape above; `levels` order defines `level_index`.
- `classifyMany` merges `['objective' => $text]` with the classify result; controller
  persists `objective_text, bloom_level, bloom_level_index, confidence, all_probabilities`.
