# Bloom classifier (local ML, no external service)

## Related File Index

| File Location | Function |
|---|---|
| `app/Services/Bloom/BloomClassifierService.php` | Request-time inference only (`classify` / `classifyMany`) |
| `app/Services/Bloom/BloomTrainingData.php` | Labeled `[text, classIndex]` samples — edit to improve accuracy, then retrain |
| `app/Console/Commands/TrainBloomModel.php` | `php artisan bloom:train` — builds vocab, trains, writes model JSON |
| `storage/app/ml/bloom_model.json` | Trained weights (generated, NOT committed — fresh clones must train) |

Pure-PHP multinomial (softmax) logistic regression over bag-of-words. No ML
library, runs on XAMPP. Levels (fixed order): Remembering, Understanding,
Applying, Analyzing, Evaluating, Creating.

Tokenizer: lowercase, strip non-`a-z`, drop tokens ≤2 chars.

## Rules agents must follow

- **Model file is NOT committed** (generated into `storage/`). Fresh clones MUST run
  `php artisan bloom:train` or every `classify*` call throws `RuntimeException`.
- **Retrain after editing `BloomTrainingData`** — otherwise the JSON weights are stale.
- Inference expects the exact JSON shape above; `levels` order defines `level_index`.
- `classifyMany` merges `['objective' => $text]` with the classify result; controller
  persists `objective_text, bloom_level, bloom_level_index, confidence, all_probabilities`.
