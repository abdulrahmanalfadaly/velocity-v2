<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Habits (Phase 5): "N times a week" schedules, a unit for counted habits, an
 * optional time on the Planner, skipped days, delete with undo, and routines
 * (an ordered group of habits done one after another).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->unsignedTinyInteger('per_week')->nullable()->after('days'); // set: "3 times a week", any days (days is then ignored)
            $table->string('unit', 24)->nullable()->after('target');            // "glasses", "pages", "min"
            $table->string('at_time', 5)->nullable()->after('unit');            // "18:00" in the user's time zone: shown and kept free on the Planner
            $table->unsignedSmallInteger('minutes')->nullable()->after('at_time');
            $table->softDeletes();
        });

        Schema::table('habit_entries', function (Blueprint $table) {
            // A skipped day (ill, on holiday): it neither breaks the streak nor counts as done.
            $table->boolean('skipped')->default(false)->after('count');
        });

        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('habit_routine', function (Blueprint $table) {
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['routine_id', 'habit_id']);
            $table->index('habit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_routine');
        Schema::dropIfExists('routines');
        Schema::table('habit_entries', fn (Blueprint $table) => $table->dropColumn('skipped'));
        Schema::table('habits', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['per_week', 'unit', 'at_time', 'minutes']);
        });
    }
};
