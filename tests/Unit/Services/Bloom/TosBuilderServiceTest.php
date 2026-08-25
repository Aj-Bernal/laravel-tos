<?php

namespace Tests\Unit\Services\Bloom;

use App\Services\Bloom\TosBuilderService;
use PHPUnit\Framework\TestCase;

class TosBuilderServiceTest extends TestCase
{
    private TosBuilderService $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new TosBuilderService();
    }

    public function test_items_per_lesson_sums_to_total_items(): void
    {
        $lessons = [
            0 => ['title' => 'Lesson A', 'weight' => 3],
            1 => ['title' => 'Lesson B', 'weight' => 1],
            2 => ['title' => 'Lesson C', 'weight' => 2],
        ];

        $result = $this->builder->buildAcrossLessons($lessons, 25);

        $this->assertSame(25, array_sum($result['items_per_lesson']));
    }

    public function test_each_lessons_bloom_levels_sum_to_that_lessons_quota(): void
    {
        $lessons = [
            0 => ['title' => 'Lesson A', 'weight' => 5],
            1 => ['title' => 'Lesson B', 'weight' => 2],
        ];

        $result = $this->builder->buildAcrossLessons($lessons, 17);

        foreach ($result['items_per_lesson'] as $key => $quota) {
            $this->assertSame(
                $quota,
                array_sum($result['levels_per_lesson'][$key]),
                "Lesson {$key}'s Bloom-level breakdown didn't match its item quota."
            );
        }
    }

    public function test_aggregate_distribution_sums_to_total_items(): void
    {
        $lessons = [
            0 => ['title' => 'Lesson A', 'weight' => 1],
            1 => ['title' => 'Lesson B', 'weight' => 1],
            2 => ['title' => 'Lesson C', 'weight' => 1],
            3 => ['title' => 'Lesson D', 'weight' => 1],
        ];

        $result = $this->builder->buildAcrossLessons($lessons, 50);

        $aggregateTotal = array_sum(array_column($result['aggregate_distribution'], 'item_count'));
        $this->assertSame(50, $aggregateTotal);
    }

    public function test_heavier_weighted_lesson_gets_more_items(): void
    {
        $lessons = [
            0 => ['title' => 'Light lesson', 'weight' => 1],
            1 => ['title' => 'Heavy lesson', 'weight' => 9],
        ];

        $result = $this->builder->buildAcrossLessons($lessons, 20);

        $this->assertGreaterThan(
            $result['items_per_lesson'][0],
            $result['items_per_lesson'][1],
            'A lesson weighted 9x heavier should receive more items than the 1x lesson.'
        );
    }

    public function test_custom_bloom_weights_are_respected_over_default(): void
    {
        $lessons = [0 => ['title' => 'Lesson A', 'weight' => 1]];

        // All weight on "Creating" -- every item should land there.
        $customWeights = [
            'Remembering' => 0.0,
            'Understanding' => 0.0,
            'Applying' => 0.0,
            'Analyzing' => 0.0,
            'Evaluating' => 0.0,
            'Creating' => 1.0,
        ];

        $result = $this->builder->buildAcrossLessons($lessons, 10, $customWeights);

        $this->assertSame(10, $result['levels_per_lesson'][0]['Creating']);
        $this->assertSame(0, $result['aggregate_distribution']['Remembering']['item_count']);
        $this->assertSame(10, $result['aggregate_distribution']['Creating']['item_count']);
    }

    public function test_single_lesson_receives_the_entire_total(): void
    {
        $lessons = [0 => ['title' => 'Only lesson', 'weight' => 1]];

        $result = $this->builder->buildAcrossLessons($lessons, 30);

        $this->assertSame(30, $result['items_per_lesson'][0]);
    }

    public function test_many_lessons_with_small_total_still_sums_correctly(): void
    {
        // Regression case for rounding drift: 10 lessons competing for
        // only 8 items -- several lessons must legitimately get 0.
        $lessons = [];
        for ($i = 0; $i < 10; $i++) {
            $lessons[$i] = ['title' => "Lesson {$i}", 'weight' => 1];
        }

        $result = $this->builder->buildAcrossLessons($lessons, 8);

        $this->assertSame(8, array_sum($result['items_per_lesson']));
    }
}
