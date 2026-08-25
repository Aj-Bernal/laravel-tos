<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tos_id',
        'title',
        'weight',
        'pdf_path',
        'item_quota',
        'level_distribution',
        'sort_order',
        'generation_status',
        'generation_started_at',
    ];

    protected $casts = [
        'level_distribution' => 'array',
        'generation_started_at' => 'datetime',
    ];

    public function tos(): BelongsTo
    {
        return $this->belongsTo(TableOfSpecification::class, 'tos_id');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(LearningObjective::class, 'lesson_id');
    }

    public function examQuestions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'lesson_id');
    }

    /**
     * A, B, C... derived from sort_order (0-indexed), used to build each
     * question's item code (e.g. lesson B's 4th question = "B4"). The
     * lessons.*.max:15 validation rule in TosController keeps this well
     * under the 26-letter ceiling; the fallback below is just a safety
     * net, not an expected path.
     */
    public function getLetterAttribute(): string
    {
        return $this->sort_order < 26
            ? chr(65 + $this->sort_order)
            : 'L'.$this->sort_order;
    }
}