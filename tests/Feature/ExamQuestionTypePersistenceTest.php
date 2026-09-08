<?php

namespace Tests\Feature;

use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\TableOfSpecification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the verdict-(d) regression: every question row used to save as the
 * DB default `multiple_choice` with NULL type-specific fields, because the
 * persistence layer silently discarded `question_type`, `is_true`,
 * `correction`, and `accepted_answers` (missing $fillable entries at the
 * time, plus a dbal-dependent migration that could never run).
 *
 * Each test below persists through the same attribute shapes
 * TosController::persistLessonResults() builds, so a silent
 * mass-assignment discard fails loudly here instead of in production.
 */
class ExamQuestionTypePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $tos = TableOfSpecification::create([
            'course' => 'Test Course',
            'total_items' => 3,
            'distribution' => [],
        ]);

        $this->lesson = Lesson::create([
            'tos_id' => $tos->id,
            'title' => 'Test Lesson',
            'pdf_path' => 'test.pdf',
        ]);
    }

    public function test_multiple_choice_round_trips_options_and_answer(): void
    {
        $q = $this->lesson->examQuestions()->create([
            'tos_id' => $this->lesson->tos_id,
            'item_number' => 1,
            'bloom_level' => 'Remembering',
            'question_type' => 'multiple_choice',
            'question' => 'What is 2 + 2?',
            'options' => ['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'],
            'correct_answer' => 'B',
        ]);

        $fresh = $q->fresh();

        $this->assertSame('multiple_choice', $fresh->question_type);
        $this->assertSame(['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'], $fresh->options);
        $this->assertSame('B', $fresh->correct_answer);
    }

    public function test_modified_true_false_false_branch_round_trips_correction(): void
    {
        $q = $this->lesson->examQuestions()->create([
            'tos_id' => $this->lesson->tos_id,
            'item_number' => 1,
            'bloom_level' => 'Applying',
            'question_type' => 'modified_true_false',
            'question' => 'Water boils at 90°C at sea level.',
            'is_true' => false,
            'correction' => 'Water boils at 100°C at sea level.',
        ]);

        $fresh = $q->fresh();

        $this->assertSame('modified_true_false', $fresh->question_type);
        $this->assertFalse($fresh->is_true);
        $this->assertSame('Water boils at 100°C at sea level.', $fresh->correction);
        $this->assertNull($fresh->options);
        $this->assertNull($fresh->correct_answer);
    }

    public function test_modified_true_false_true_branch_stores_null_correction(): void
    {
        $q = $this->lesson->examQuestions()->create([
            'tos_id' => $this->lesson->tos_id,
            'item_number' => 1,
            'bloom_level' => 'Understanding',
            'question_type' => 'modified_true_false',
            'question' => 'The Earth orbits the Sun.',
            'is_true' => true,
            'correction' => null,
        ]);

        $fresh = $q->fresh();

        $this->assertSame('modified_true_false', $fresh->question_type);
        $this->assertTrue($fresh->is_true);
        $this->assertNull($fresh->correction);
    }

    public function test_enumeration_round_trips_accepted_answers(): void
    {
        $q = $this->lesson->examQuestions()->create([
            'tos_id' => $this->lesson->tos_id,
            'item_number' => 1,
            'bloom_level' => 'Understanding',
            'question_type' => 'enumeration',
            'question' => 'Enumerate the three states of matter.',
            'accepted_answers' => ['solid', 'liquid', 'gas'],
        ]);

        $fresh = $q->fresh();

        $this->assertSame('enumeration', $fresh->question_type);
        $this->assertSame(['solid', 'liquid', 'gas'], $fresh->accepted_answers);
        $this->assertNull($fresh->options);
        $this->assertNull($fresh->correct_answer);
    }

    public function test_question_type_column_defaults_to_multiple_choice(): void
    {
        $q = $this->lesson->examQuestions()->create([
            'tos_id' => $this->lesson->tos_id,
            'item_number' => 1,
            'bloom_level' => 'Remembering',
            'question' => 'Legacy row without an explicit type.',
            'options' => ['A' => 'x', 'B' => 'y', 'C' => 'z', 'D' => 'w'],
            'correct_answer' => 'A',
        ]);

        $this->assertSame('multiple_choice', $q->fresh()->question_type);
    }
}
