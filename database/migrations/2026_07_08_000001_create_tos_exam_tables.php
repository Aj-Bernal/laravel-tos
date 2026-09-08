<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_of_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('course');
            $table->unsignedInteger('total_items');
            $table->json('distribution'); // aggregated per-level {weight, item_count, objective_count} across all lessons
            $table->timestamps();
        });

        // One row per uploaded lesson PDF within a course/TOS.
        // "weight" = teacher's relative emphasis (e.g. class hours/days spent),
        // used to proportion how many exam items are drawn from this lesson.
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tos_id')->constrained('table_of_specifications')->cascadeOnDelete();
            $table->string('title');
            $table->float('weight')->default(1);
            $table->string('pdf_path');
            $table->unsignedInteger('item_quota')->default(0); // computed: this lesson's share of total_items
            $table->json('level_distribution')->nullable(); // this lesson's per-Bloom's-level item counts
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tos_id')->constrained('table_of_specifications')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->text('objective_text');
            $table->string('bloom_level');
            $table->unsignedTinyInteger('bloom_level_index');
            $table->float('confidence');
            $table->json('all_probabilities')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tos_id')->constrained('table_of_specifications')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('bloom_level');
            $table->text('question');
            // Nullable from day one: Modified True-or-False and Enumeration
            // questions don't use options / correct_answer (they use is_true
            // + correction / accepted_answers instead, added in the
            // 2026_07_13 migration). Keeping them nullable here avoids a
            // ->change() alter later, which would require doctrine/dbal.
            $table->json('options')->nullable();
            $table->string('correct_answer', 1)->nullable();
            $table->text('rationale')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('learning_objectives');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('table_of_specifications');
    }
};
