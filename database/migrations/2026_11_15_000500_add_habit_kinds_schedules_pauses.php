<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habits: kinds (do it, minutes, a limit, avoid), "N times a month" and "every
 * N days" schedules, pauses, and a note on each check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->string('kind', 8)->default('do')->after('name'); // do, minutes, limit, avoid
            $table->unsignedTinyInteger('per_month')->nullable()->after('per_week');
            $table->unsignedSmallInteger('every_days')->nullable()->after('per_month');
            $table->date('starts_on')->nullable()->after('every_days'); // "every N days" counts from here
        });
        Schema::table('habit_entries', function (Blueprint $table) {
            $table->string('note', 300)->nullable()->after('skipped');
        });
        Schema::create('habit_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('from');
            $table->date('until')->nullable(); // null: until resumed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_pauses');
        Schema::table('habit_entries', function (Blueprint $table) {
            $table->dropColumn('note');
        });
        Schema::table('habits', function (Blueprint $table) {
            $table->dropColumn(['kind', 'per_month', 'every_days', 'starts_on']);
        });
    }
};
