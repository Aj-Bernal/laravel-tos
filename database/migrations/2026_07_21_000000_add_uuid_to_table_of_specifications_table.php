<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Why this exists: with no auth wired in yet, TosPolicy::access() lets
 * anyone view/act on any TOS that has no owner (user_id is null) — which
 * today is EVERY TOS, since nothing sets user_id. Route model binding on
 * `/tos/{tos}` used the auto-increment `id`, so a TOS was trivially
 * discoverable by just walking /tos/1, /tos/2, /tos/3...
 *
 * This doesn't replace real auth/ownership (still needed — see
 * TosPolicy's docblock), but it closes the "guess a small integer" hole
 * immediately: public URLs now use an unguessable UUID instead of the
 * sequential id. `id` stays the primary key for all internal
 * relations/foreign keys (lessons.tos_id, etc.) — unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_of_specifications', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Backfill existing rows so nothing is left without a public
        // identifier once the model starts routing by uuid.
        DB::table('table_of_specifications')
            ->whereNull('uuid')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($row) {
                DB::table('table_of_specifications')
                    ->where('id', $row->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        Schema::table('table_of_specifications', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
