<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several reminders per task: at a time, before the due date (moves with it),
 * or "Bug me" every so often until the task is done. Each task's one old
 * reminder moves over as an "at a time" reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // at, before, bug
            $table->dateTime('at')->nullable(); // at: the time
            $table->unsignedSmallInteger('minutes_before')->nullable(); // before: 15 / 60 / 180 minutes before the due time
            $table->unsignedTinyInteger('days_before')->nullable(); // before: 0 (the due day) / 1 / 2 / 7 days before …
            $table->string('at_time', 5)->nullable(); // … at this time of day ("09:00")
            $table->unsignedSmallInteger('every_minutes')->nullable(); // bug: how often
            $table->dateTime('next_at')->nullable(); // when it shows next (null: waiting for a due date)
            $table->dateTime('sent_at')->nullable(); // when it was last shown
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'next_at']);
        });

        $now = now();
        foreach (DB::table('tasks')->whereNotNull('reminder_at')->get(['id', 'user_id', 'reminder_at', 'reminder_sent_at']) as $t) {
            DB::table('task_reminders')->insert([
                'task_id' => $t->id, 'user_id' => $t->user_id, 'kind' => 'at', 'at' => $t->reminder_at,
                'next_at' => $t->reminder_at, 'sent_at' => $t->reminder_sent_at, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'reminder_at']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['reminder_at', 'reminder_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('reminder_at')->nullable()->after('score_at_completion');
            $table->dateTime('reminder_sent_at')->nullable()->after('reminder_at');
            $table->index(['user_id', 'reminder_at']);
        });
        foreach (DB::table('task_reminders')->where('kind', 'at')->whereNull('deleted_at')->orderBy('id')->get() as $r) {
            DB::table('tasks')->where('id', $r->task_id)->whereNull('reminder_at')->update(['reminder_at' => $r->at, 'reminder_sent_at' => $r->sent_at]);
        }
        Schema::dropIfExists('task_reminders');
    }
};
