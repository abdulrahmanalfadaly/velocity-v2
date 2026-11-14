<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Focus: Pomodoro rounds and their breaks, an intention before and a note
 * after, deleting a session from the history (with Undo), and the Focus
 * settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->string('mode', 10)->default('timer')->after('task_id'); // timer, pomodoro
            $table->unsignedTinyInteger('round')->nullable()->after('mode'); // Pomodoro: 1, 2, 3 …
            $table->string('intention', 240)->nullable()->after('round');
            $table->string('note', 1000)->nullable()->after('intention');
            // Pomodoro: the break after this round (set when the round ends; cleared when the next starts or you're done).
            $table->unsignedSmallInteger('break_minutes')->nullable()->after('seconds');
            $table->dateTime('break_started_at')->nullable()->after('break_minutes');
            $table->softDeletes();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->json('focus_settings')->nullable()->after('auto_archive_days');
        });
    }

    public function down(): void
    {
        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->dropColumn(['mode', 'round', 'intention', 'note', 'break_minutes', 'break_started_at', 'deleted_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('focus_settings');
        });
    }
};
