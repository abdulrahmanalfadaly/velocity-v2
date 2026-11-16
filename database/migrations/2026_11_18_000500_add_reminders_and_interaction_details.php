<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dates: several reminders each ([{days: 7, at: "09:00"}, …]), which ones went off for
 * which year ("2026-11-20#7"), and a snooze. The old single reminder (remind_days at
 * 9:00) becomes the first of the list.
 * Interactions: a time of day, and whether it counts toward keeping in touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_dates', function (Blueprint $table) {
            $table->json('reminders')->nullable()->after('remind_days');
            $table->json('reminded')->nullable()->after('reminded_for');
            $table->dateTime('snoozed_until')->nullable()->after('reminded');
        });
        foreach (DB::table('person_dates')->whereNotNull('remind_days')->get() as $d) {
            DB::table('person_dates')->where('id', $d->id)->update([
                'reminders' => json_encode([['days' => (int) $d->remind_days, 'at' => '09:00']]),
                'reminded' => $d->reminded_for ? json_encode([substr((string) $d->reminded_for, 0, 10).'#'.(int) $d->remind_days]) : null,
            ]);
        }
        Schema::table('catch_ups', function (Blueprint $table) {
            $table->string('at', 5)->nullable()->after('on');
            $table->boolean('counts')->default(true)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('catch_ups', fn (Blueprint $table) => $table->dropColumn(['at', 'counts']));
        Schema::table('person_dates', fn (Blueprint $table) => $table->dropColumn(['reminders', 'reminded', 'snoozed_until']));
    }
};
