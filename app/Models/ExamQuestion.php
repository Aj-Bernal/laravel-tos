<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'tos_id',
        'lesson_id',
        'item_number',
        'bloom_level',
        'question_type',
        'question',
        'options',
        'correct_answer',
        'is_true',
        'correction',
        'accepted_answers',
        'rationale',
    ];

    protected $casts = [
        'options' => 'array',
        'is_true' => 'boolean',
        'accepted_answers' => 'array',
    ];

    public function tos(): BelongsTo
    {
        return $this->belongsTo(TableOfSpecification::class, 'tos_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}