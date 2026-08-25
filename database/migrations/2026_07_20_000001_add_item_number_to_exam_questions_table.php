<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_number is the per-lesson sequence number assigned to a generated
 * exam question (1, 2, 3...). Combined with the lesson's letter (A, B,
 * C... derived from its sort_order — see Lesson::getLetterAttribute()),
 * this produces the "A1", "B4"-style item codes used by
 * ItemPlacementService to build the Item Placement grouping.
 *
 * Nullable because existing exam questions generated before this feature
 * existed have no item number — they're simply left out of the Item
 * Placement grouping rather than backfilled with a guessed number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->unsignedInteger('item_number')->nullable()->after('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn('item_number');
        });
    }
};
