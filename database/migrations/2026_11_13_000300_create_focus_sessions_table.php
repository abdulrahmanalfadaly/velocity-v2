<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Focus sessions. Time is worked out from timestamps (started, paused, ended), never
 * from a counter in the window, so closing or reloading Velocity loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('planned_minutes')->nullable(); // null = no time limit
            $table->dateTime('started_at');
            $table->dateTime('paused_at')->nullable();                     // set while paused
            $table->unsignedInteger('paused_seconds')->default(0);         // earlier pauses, added up
            $table->dateTime('ended_at')->nullable();                      // null = still running
            $table->unsignedInteger('seconds')->nullable();                // focused time, once ended
            $table->string('was_status', 16)->nullable();                  // the task's status before, for Undo
            $table->timestamps();
            $table->index(['user_id', 'ended_at']);
            $table->index(['task_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_sessions');
    }
};
