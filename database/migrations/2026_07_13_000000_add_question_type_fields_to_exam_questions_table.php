<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the three-question-type columns to `exam_questions`.
 *
 * NOTE: `options` / `correct_answer` are already nullable from the base
 * create migration (2026_07_08), because Modified True-or-False and
 * Enumeration questions don't use them — so this migration only ADDS
 * columns and intentionally avoids ->change(), which would require
 * doctrine/dbal. Do not add ->change() calls here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->string('question_type')->default('multiple_choice')->after('bloom_level');
            $table->boolean('is_true')->nullable()->after('correct_answer');
            $table->text('correction')->nullable()->after('is_true');
            $table->json('accepted_answers')->nullable()->after('correction');
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn(['question_type', 'is_true', 'correction', 'accepted_answers']);
        });
    }
};
