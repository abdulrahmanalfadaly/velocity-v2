<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Events: a locked one asks before it is dragged; a cancelled day stays on the
 * Planner crossed out (and counts in Review) instead of disappearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('repeat_until');
            $table->json('cancelled')->nullable()->after('skipped'); // local dates, "2026-11-18"
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['locked', 'cancelled']));
    }
};
