<?php

namespace App\Console\Commands;

use App\Services\Bloom\BloomClassifierService;
use App\Services\Bloom\BloomTrainingData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan bloom:train
 *
 * Trains the multinomial logistic regression model on the labeled
 * objectives in BloomTrainingData and writes the weights to
 * storage/app/ml/bloom_model.json for BloomClassifierService to load.
 *
 * Re-run this any time you add more labeled examples to
 * BloomTrainingData::samples() to improve accuracy.
 */
class TrainBloomModel extends Command
{
    protected $signature = 'bloom:train {--epochs=300} {--lr=0.5} {--lambda=0.001} {--val-split=0.15} {--seed=42} {--log-every=50}';

    protected $description = "Train the Bloom's Taxonomy logistic regression classifier";

    public function handle(): int
    {
        $samples = BloomTrainingData::samples();
        $levels = BloomClassifierService::LEVELS;
        $numClasses = count($levels);

        $this->info('Building vocabulary from '.count($samples).' labeled objectives...');

        // Unigrams + bigrams (see BloomClassifierService::ngrams) so the
        // model can key off cue-verb + object phrases ("identify the root
        // cause"), not just isolated words that repeat across every level.
        $vocabCounts = [];
        foreach ($samples as [$text, $label]) {
            foreach (BloomClassifierService::ngrams($text) as $tok) {
                $vocabCounts[$tok] = ($vocabCounts[$tok] ?? 0) + 1;
            }
        }
        // Drop hapax legomena (n-grams seen only once) — with bigrams the
        // vocabulary explodes and singletons are almost always noise that
        // just lets the model memorize individual training sentences.
        $vocab = array_keys(array_filter($vocabCounts, fn ($c) => $c >= 2));
        sort($vocab);
        $vocabIndex = array_flip($vocab);
        $vocabSize = count($vocab);

        $this->info("Vocabulary size: {$vocabSize} n-grams (min doc freq 2)");

        $X = [];
        $y = [];
        foreach ($samples as [$text, $label]) {
            $vec = array_fill(0, $vocabSize, 0.0);
            foreach (BloomClassifierService::ngrams($text) as $tok) {
                if (isset($vocabIndex[$tok])) {
                    $vec[$vocabIndex[$tok]] += 1.0;
                }
            }
            $X[] = $vec;
            $y[] = $label;
        }

        $weights = array_fill(0, $numClasses, array_fill(0, $vocabSize, 0.0));
        $bias = array_fill(0, $numClasses, 0.0);

        $lr = (float) $this->option('lr');
        $epochs = (int) $this->option('epochs');
        $lambda = (float) $this->option('lambda');
        $logEvery = max(1, (int) $this->option('log-every'));

        // Stratified train/validation split so you can tell whether the
        // model is actually converging to something that generalizes, not
        // just fitting the training set. Validation samples are held out
        // entirely from the gradient updates below and only used for the
        // reported accuracy metric. Pass --val-split=0 to train on 100% of
        // the data (matches the old behavior) once you've picked settings
        // you're happy with.
        $valSplit = (float) $this->option('val-split');
        mt_srand((int) $this->option('seed'));

        $byClass = array_fill(0, $numClasses, []);
        foreach ($y as $i => $label) {
            $byClass[$label][] = $i;
        }

        $trainIdx = [];
        $valIdx = [];
        foreach ($byClass as $classIndices) {
            shuffle($classIndices);
            $valCount = $valSplit > 0 ? max(1, (int) round(count($classIndices) * $valSplit)) : 0;
            $valIdx = array_merge($valIdx, array_slice($classIndices, 0, $valCount));
            $trainIdx = array_merge($trainIdx, array_slice($classIndices, $valCount));
        }
        shuffle($trainIdx);

        $numTrain = count($trainIdx);

        $this->info("Training on {$numTrain} samples, holding out ".count($valIdx).' for validation.');

        $bar = $this->output->createProgressBar($epochs);
        $bar->start();

        $lossHistory = [];

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradW = array_fill(0, $numClasses, array_fill(0, $vocabSize, 0.0));
            $gradB = array_fill(0, $numClasses, 0.0);
            $epochLoss = 0.0;

            foreach ($trainIdx as $i) {
                $z = [];
                for ($c = 0; $c < $numClasses; $c++) {
                    $dot = $bias[$c];
                    foreach ($X[$i] as $f => $val) {
                        if ($val != 0.0) {
                            $dot += $weights[$c][$f] * $val;
                        }
                    }
                    $z[$c] = $dot;
                }

                $max = max($z);
                $exps = array_map(fn ($v) => exp($v - $max), $z);
                $sum = array_sum($exps);
                $probs = array_map(fn ($v) => $v / $sum, $exps);

                $trueClass = $y[$i];
                $epochLoss += -log(max($probs[$trueClass], 1e-12));

                for ($c = 0; $c < $numClasses; $c++) {
                    $error = $probs[$c] - ($c === $trueClass ? 1.0 : 0.0);
                    foreach ($X[$i] as $f => $val) {
                        if ($val != 0.0) {
                            $gradW[$c][$f] += $error * $val;
                        }
                    }
                    $gradB[$c] += $error;
                }
            }

            for ($c = 0; $c < $numClasses; $c++) {
                for ($f = 0; $f < $vocabSize; $f++) {
                    $reg = $lambda * $weights[$c][$f];
                    $weights[$c][$f] -= $lr * (($gradW[$c][$f] / $numTrain) + $reg);
                }
                $bias[$c] -= $lr * ($gradB[$c] / $numTrain);
            }

            $avgLoss = $epochLoss / $numTrain;
            $lossHistory[$epoch] = $avgLoss;

            if (($epoch + 1) % $logEvery === 0 || $epoch === $epochs - 1) {
                $bar->clear();
                $this->line(sprintf('  epoch %4d  train loss: %.4f', $epoch + 1, $avgLoss));
                $bar->display();
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // Accuracy on both sets: a big train/validation gap is the signal
        // that more epochs (or a bigger vocabulary) is starting to
        // memorize rather than generalize -- loss alone won't show you that.
        $trainAccuracy = $this->accuracy($X, $y, $trainIdx, $weights, $bias, $numClasses);
        $valAccuracy = count($valIdx) > 0
            ? $this->accuracy($X, $y, $valIdx, $weights, $bias, $numClasses)
            : null;

        $model = [
            'vocab' => $vocab,
            'weights' => $weights,
            'bias' => $bias,
            'levels' => $levels,
            'trained_at' => now()->toIso8601String(),
            'sample_count' => $numTrain,
        ];

        Storage::put('ml/bloom_model.json', json_encode($model));

        $this->info('Model trained and saved to storage/app/ml/bloom_model.json');

        $rows = [
            ['Training samples', $numTrain],
            ['Validation samples', count($valIdx)],
            ['Vocabulary size', $vocabSize],
            ['Classes', $numClasses],
            ['Epochs', $epochs],
            ['Final train loss', round(end($lossHistory), 4)],
            ['Train accuracy', round($trainAccuracy * 100, 1).'%'],
        ];
        if ($valAccuracy !== null) {
            $rows[] = ['Validation accuracy', round($valAccuracy * 100, 1).'%'];
        }

        $this->table(['Metric', 'Value'], $rows);

        if ($valAccuracy !== null && ($trainAccuracy - $valAccuracy) > 0.15) {
            $this->warn(
                'Train accuracy is notably higher than validation accuracy -- this usually '.
                'means the model is overfitting (memorizing the training set rather than '.
                'learning generalizable patterns). Consider more training data, fewer epochs, '.
                'or a larger --lambda.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<int, float>> $X
     * @param array<int, int> $y
     * @param array<int, int> $indices
     * @param array<int, array<int, float>> $weights
     * @param array<int, float> $bias
     */
    private function accuracy(array $X, array $y, array $indices, array $weights, array $bias, int $numClasses): float
    {
        if (count($indices) === 0) {
            return 0.0;
        }

        $correct = 0;
        foreach ($indices as $i) {
            $z = [];
            for ($c = 0; $c < $numClasses; $c++) {
                $dot = $bias[$c];
                foreach ($X[$i] as $f => $val) {
                    if ($val != 0.0) {
                        $dot += $weights[$c][$f] * $val;
                    }
                }
                $z[$c] = $dot;
            }
            $predicted = array_keys($z, max($z))[0];
            if ($predicted === $y[$i]) {
                $correct++;
            }
        }

        return $correct / count($indices);
    }
}