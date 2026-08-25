<?php

namespace App\Services\Bloom;

use App\Models\TableOfSpecification;

/**
 * Groups a TOS's generated exam items into N roughly-equal "placement"
 * sections (I, II, III... 5 by default, matching the CvSU TOS sample),
 * spiraling item order across lessons within each Bloom's level first so
 * a single section doesn't end up dominated by one lesson.
 *
 * Confirmed against the CvSU Arts and Sciences Department sample: the
 * "Item Placement" grid's columns are the three difficulty tiers (easy /
 * moderate / hard), not the six individual Bloom's levels. Each tier
 * groups a pair of Bloom's levels, per the standard convention:
 *   Easy      = Remembering, Understanding
 *   Moderate  = Applying, Analyzing
 *   Hard      = Evaluating, Creating
 */
class ItemPlacementService
{
    private const DEFAULT_GROUPS = 5; // matches the I-V sections on the sample

    /**
     * Bloom's level => difficulty tier, per the CvSU sample's
     * "easy / moderate / hard" Item Placement columns.
     */
    public const DIFFICULTY_TIERS = [
        'Remembering' => 'Easy',
        'Understanding' => 'Easy',
        'Applying' => 'Moderate',
        'Analyzing' => 'Moderate',
        'Evaluating' => 'Hard',
        'Creating' => 'Hard',
    ];

    /**
     * @return array<string, string[]> Roman numeral label => item codes, e.g. ['I' => ['A3', 'B1'], ...]
     */
    public function group(TableOfSpecification $tos, int $numGroups = self::DEFAULT_GROUPS): array
    {
        $spiraled = array_map(fn ($pair) => $pair[0], $this->spiralOrderWithLevel($tos));

        return $this->splitIntoGroups($spiraled, $numGroups);
    }

    /**
     * Same row split as group(), but keeps each item's difficulty tier so
     * the result can be rendered as a grid: Roman numeral row x
     * easy/moderate/hard column, matching the CvSU template's Item
     * Placement section. An item lands in whichever column matches the
     * difficulty tier of its own already-classified Bloom level (see
     * DIFFICULTY_TIERS).
     *
     * @return array<string, array<string, string[]>> e.g. ['I' => ['Easy' => ['A3'], 'Moderate' => ['A1', 'A2'], 'Hard' => []], ...]
     */
    public function groupGrid(TableOfSpecification $tos, int $numGroups = self::DEFAULT_GROUPS): array
    {
        $spiraledWithLevel = $this->spiralOrderWithLevel($tos);

        if (empty($spiraledWithLevel) || $numGroups < 1) {
            return [];
        }

        $numGroups = min($numGroups, count($spiraledWithLevel));
        $counts = $this->rowCounts(count($spiraledWithLevel), $numGroups);
        $romanNumerals = $this->romanNumerals($numGroups);

        $grid = [];
        $cursor = 0;
        foreach (range(0, $numGroups - 1) as $groupIndex) {
            $size = $counts[$groupIndex];
            $slice = array_slice($spiraledWithLevel, $cursor, $size);
            $cursor += $size;

            $byTier = array_fill_keys(['Easy', 'Moderate', 'Hard'], []);
            foreach ($slice as [$code, $level]) {
                $tier = self::DIFFICULTY_TIERS[$level] ?? 'Moderate';
                $byTier[$tier][] = $code;
            }

            $grid[$romanNumerals[$groupIndex]] = $byTier;
        }

        return $grid;
    }

    /**
     * Builds the full list of [item code, bloom level] pairs in spiraled
     * order: within each Bloom's level, cycles through lessons round-robin
     * (lesson A's first item, lesson B's first item, ..., lesson A's
     * second item, ...) rather than listing one lesson's items all
     * together, then moves on to the next Bloom's level. Items without an
     * item_number (generated before this feature existed) are skipped.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function spiralOrderWithLevel(TableOfSpecification $tos): array
    {
        $byLevel = [];

        foreach ($tos->lessons as $lesson) {
            foreach ($lesson->examQuestions->sortBy('item_number') as $question) {
                if ($question->item_number === null) {
                    continue;
                }

                $byLevel[$question->bloom_level][$lesson->id][] = $lesson->letter.$question->item_number;
            }
        }

        $ordered = [];
        foreach ($byLevel as $level => $lessonBuckets) {
            foreach ($this->interleave($lessonBuckets) as $code) {
                $ordered[] = [$code, $level];
            }
        }

        return $ordered;
    }

    /**
     * Round-robins across each lesson's bucket of item codes.
     *
     * @param array<int, string[]> $buckets Lesson id => item codes
     * @return string[]
     */
    private function interleave(array $buckets): array
    {
        $result = [];

        while (array_filter($buckets)) {
            foreach ($buckets as &$bucket) {
                if (!empty($bucket)) {
                    $result[] = array_shift($bucket);
                }
            }
            unset($bucket);
        }

        return $result;
    }

    /**
     * @param string[] $codes
     * @return array<string, string[]>
     */
    private function splitIntoGroups(array $codes, int $numGroups): array
    {
        if (empty($codes) || $numGroups < 1) {
            return [];
        }

        $numGroups = min($numGroups, count($codes));
        $counts = $this->rowCounts(count($codes), $numGroups);
        $romanNumerals = $this->romanNumerals($numGroups);

        $groups = [];
        $cursor = 0;
        foreach (range(0, $numGroups - 1) as $groupIndex) {
            $size = $counts[$groupIndex];
            $groups[$romanNumerals[$groupIndex]] = array_slice($codes, $cursor, $size);
            $cursor += $size;
        }

        return $groups;
    }

    /**
     * Splits $total items as evenly as possible across $numGroups rows
     * using the same largest-remainder apportionment used everywhere
     * else in the app, so row sizes never drift from the total.
     *
     * @return array<int, int>
     */
    private function rowCounts(int $total, int $numGroups): array
    {
        $rawCounts = array_fill_keys(range(0, $numGroups - 1), $total / $numGroups);

        return ApportionmentService::apportion($rawCounts, $total);
    }

    /**
     * @return array<int, string>
     */
    private function romanNumerals(int $count): array
    {
        $numerals = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];

        return array_slice($numerals, 0, $count);
    }
}