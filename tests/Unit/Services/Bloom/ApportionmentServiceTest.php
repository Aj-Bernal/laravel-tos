<?php

namespace Tests\Unit\Services\Bloom;

use App\Services\Bloom\ApportionmentService;
use PHPUnit\Framework\TestCase;

/**
 * ApportionmentService is pure logic (no DB, no HTTP), so it's the
 * cheapest and highest-value thing in the pipeline to lock down with
 * tests: every other allocation (lessons -> items, items -> Bloom
 * levels, levels -> question types, item-placement groups) depends on
 * it always summing exactly to the requested total.
 */
class ApportionmentServiceTest extends TestCase
{
    public function test_result_always_sums_to_total_even_with_messy_percentages(): void
    {
        // 20/20/20/15/15/10 against small totals is exactly the case that
        // drifts under naive rounding (e.g. round(20% of 7) six times
        // won't sum to 7). This is the real default TOS Bloom split.
        $weights = [
            'Remembering' => 0.20,
            'Understanding' => 0.20,
            'Applying' => 0.20,
            'Analyzing' => 0.15,
            'Evaluating' => 0.15,
            'Creating' => 0.10,
        ];

        foreach ([1, 2, 3, 5, 7, 10, 13, 27, 50, 99, 200] as $total) {
            $raw = array_map(fn ($w) => $w * $total, $weights);
            $result = ApportionmentService::apportion($raw, $total);

            $this->assertSame(
                $total,
                array_sum($result),
                "Total items ({$total}) did not sum correctly across Bloom levels."
            );
        }
    }

    public function test_every_bucket_is_non_negative(): void
    {
        $raw = ['A' => 0.4, 'B' => 0.4, 'B2' => 0.4, 'C' => 0.4, 'D' => 0.4];
        $result = ApportionmentService::apportion($raw, 3);

        foreach ($result as $bucket => $count) {
            $this->assertGreaterThanOrEqual(0, $count, "Bucket {$bucket} went negative.");
        }
        $this->assertSame(3, array_sum($result));
    }

    public function test_largest_remainder_gets_priority_on_ties_and_near_ties(): void
    {
        // 10 total split 3 ways evenly -> 3.33 each. Floors give 3+3+3=9,
        // one remaining item goes to (in our stable-order tie-break) the
        // first bucket by iteration order.
        $raw = ['A' => 10 / 3, 'B' => 10 / 3, 'C' => 10 / 3];
        $result = ApportionmentService::apportion($raw, 10);

        $this->assertSame(10, array_sum($result));
        // No bucket should be more than 1 away from the floor (4 max, 3 min).
        foreach ($result as $count) {
            $this->assertContains($count, [3, 4]);
        }
    }

    public function test_single_bucket_takes_the_whole_total(): void
    {
        $result = ApportionmentService::apportion(['OnlyLesson' => 1.0], 8);
        $this->assertSame(['OnlyLesson' => 8], $result);
    }

    public function test_zero_total_returns_all_zero_buckets(): void
    {
        $result = ApportionmentService::apportion(['A' => 0.5, 'B' => 0.5], 0);
        $this->assertSame(['A' => 0, 'B' => 0], $result);
    }

    public function test_empty_raw_counts_returns_empty_array(): void
    {
        $this->assertSame([], ApportionmentService::apportion([], 10));
    }

    public function test_negative_total_returns_all_zero_buckets(): void
    {
        $result = ApportionmentService::apportion(['A' => 0.5, 'B' => 0.5], -5);
        $this->assertSame(['A' => 0, 'B' => 0], $result);
    }

    public function test_weights_that_overshoot_the_total_are_trimmed_down(): void
    {
        // Deliberately pass raw counts that sum to MORE than $total, to
        // exercise the $remaining < 0 branch (weights that don't sum
        // cleanly to 1.0, e.g. a caller-supplied custom Bloom split).
        $raw = ['A' => 5.0, 'B' => 5.0, 'C' => 5.0]; // sums to 15
        $result = ApportionmentService::apportion($raw, 10);

        $this->assertSame(10, array_sum($result));
        foreach ($result as $count) {
            $this->assertGreaterThanOrEqual(0, $count);
        }
    }

    public function test_string_and_integer_keys_are_both_preserved(): void
    {
        // TosBuilderService::buildAcrossLessons passes lessons keyed by
        // array index (int), while Bloom-level allocation passes them
        // keyed by level name (string) -- both must round-trip cleanly.
        $result = ApportionmentService::apportion([0 => 2.5, 1 => 2.5, 2 => 5.0], 10);

        $this->assertSame([0, 1, 2], array_keys($result));
        $this->assertSame(10, array_sum($result));
    }
}
