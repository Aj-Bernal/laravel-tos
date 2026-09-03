<?php

namespace App\Services\Bloom;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Multinomial (softmax) Logistic Regression classifier for Bloom's
 * Taxonomy levels. Pure PHP — no external ML library required, so it
 * runs anywhere Laravel runs (XAMPP included).
 *
 * Model is trained offline via `php artisan bloom:train` and stored as
 * JSON (storage/app/ml/bloom_model.json). This service only loads the
 * weights and runs prediction (cheap, no training at request time).
 */
class BloomClassifierService
{
    public const LEVELS = [
        'Remembering',
        'Understanding',
        'Applying',
        'Analyzing',
        'Evaluating',
        'Creating',
    ];

    private const MODEL_PATH = 'ml/bloom_model.json';

    private ?array $model = null;

    private function loadModel(): array
    {
        if ($this->model !== null) {
            return $this->model;
        }

        if (!Storage::exists(self::MODEL_PATH)) {
            throw new RuntimeException(
                'Bloom model not found. Run `php artisan bloom:train` first to generate storage/app/'.self::MODEL_PATH
            );
        }

        $this->model = json_decode(Storage::get(self::MODEL_PATH), true);

        return $this->model;
    }

    /**
     * Tokenize + lowercase + strip punctuation.
     *
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z\s]/', ' ', $text);
        $tokens = preg_split('/\s+/', trim($text));

        return array_values(array_filter($tokens, fn ($t) => strlen($t) > 2));
    }

    /**
     * Unigrams + bigrams ("evaluate", "evaluate the", "the fairness").
     *
     * Bloom's level is usually signaled by a cue VERB + its OBJECT
     * together ("identify the root cause" = Analyzing, not the isolated
     * word "identify" which appears across every level). A pure unigram
     * bag-of-words can't see that; bigrams give the classifier access to
     * that local phrase context without needing a full parser.
     *
     * @return string[]
     */
    public static function ngrams(string $text): array
    {
        $tokens = self::tokenize($text);
        $grams = $tokens;

        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $grams[] = $tokens[$i].'_'.$tokens[$i + 1];
        }

        return $grams;
    }

    /**
     * Bag-of-(uni+bi)grams feature vector against the trained vocabulary.
     *
     * @return float[]
     */
    private function featurize(string $text, array $vocabIndex, int $vocabSize): array
    {
        $vec = array_fill(0, $vocabSize, 0.0);
        foreach (self::ngrams($text) as $tok) {
            if (isset($vocabIndex[$tok])) {
                $vec[$vocabIndex[$tok]] += 1.0;
            }
        }

        return $vec;
    }

    private static function softmax(array $z): array
    {
        $max = max($z);
        $exps = array_map(fn ($v) => exp($v - $max), $z);
        $sum = array_sum($exps);

        return array_map(fn ($v) => $v / $sum, $exps);
    }

    /**
     * Classify a single learning objective.
     *
     * @return array{level: string, level_index: int, confidence: float, all_probabilities: array<string,float>}
     */
    public function classify(string $objectiveText): array
    {
        $model = $this->loadModel();
        $vocabIndex = array_flip($model['vocab']);
        $vocabSize = count($model['vocab']);

        $vec = $this->featurize($objectiveText, $vocabIndex, $vocabSize);

        $z = [];
        $numClasses = count($model['levels']);
        for ($c = 0; $c < $numClasses; $c++) {
            $dot = $model['bias'][$c];
            foreach ($vec as $f => $val) {
                if ($val != 0.0) {
                    $dot += $model['weights'][$c][$f] * $val;
                }
            }
            $z[$c] = $dot;
        }

        $probs = self::softmax($z);
        $bestIndex = array_keys($probs, max($probs))[0];

        $allProbabilities = [];
        foreach ($model['levels'] as $i => $levelName) {
            $allProbabilities[$levelName] = round($probs[$i] * 100, 1);
        }

        return [
            'level' => $model['levels'][$bestIndex],
            'level_index' => $bestIndex,
            'confidence' => round($probs[$bestIndex] * 100, 1),
            'all_probabilities' => $allProbabilities,
        ];
    }

    /**
     * Classify multiple objectives at once (e.g. one Blade textarea per line).
     *
     * @param string[] $objectives
     * @return array<int, array>
     */
    public function classifyMany(array $objectives): array
    {
        return array_map(fn ($text) => array_merge(
            ['objective' => $text],
            $this->classify($text)
        ), $objectives);
    }
}