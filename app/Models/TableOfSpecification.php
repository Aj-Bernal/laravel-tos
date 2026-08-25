<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TableOfSpecification extends Model
{
    use HasFactory;

    protected $table = 'table_of_specifications';

    protected $fillable = [
        'user_id',
        'course',
        'total_items',
        'distribution',
    ];

    protected $casts = [
        'distribution' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tos) {
            $tos->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Public URLs (`/tos/{tos}`, JSON `tos_id`/`id` fields, etc.) use this
     * unguessable uuid instead of the sequential `id`, so a TOS can't be
     * discovered by walking /tos/1, /tos/2, ... . `id` remains the primary
     * key for all internal relations (lessons.tos_id and friends) — this
     * only changes what route-model-binding matches on and what gets
     * serialized to the client.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'tos_id')->orderBy('sort_order');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(LearningObjective::class, 'tos_id');
    }

    public function examQuestions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'tos_id');
    }
}