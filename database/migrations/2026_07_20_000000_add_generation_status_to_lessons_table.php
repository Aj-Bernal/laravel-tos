<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * generation_status is the lock this migration exists to support:
     * generateExam() / generateExamForLesson() flip a lesson from
     * 'pending' (or 'failed') to 'generating' via a single conditional
     * UPDATE before doing any work, so two concurrent requests can't both
     * pass an exists()-style check and duplicate a lesson's questions.
     *
     * generation_started_at lets a stuck 'generating' lock (server
     * timeout or crashed worker mid-request, never reaching the code that
     * marks 'completed'/'failed') be reclaimed after it goes stale,
     * instead of leaving that lesson permanently unretryable.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('generation_status')->default('pending')->after('level_distribution');
            $table->timestamp('generation_started_at')->nullable()->after('generation_status');
        });

        // Backfill: lessons that already have saved exam questions are
        // already done, not 'pending' — otherwise the very first claim
        // attempt after this migration runs would treat a completed
        // lesson as eligible for regeneration and duplicate its questions.
        DB::table('lessons')
            ->whereIn('id', function ($query) {
                $query->select('lesson_id')->from('exam_questions')->distinct();
            })
            ->update(['generation_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['generation_status', 'generation_started_at']);
        });
    }
};
