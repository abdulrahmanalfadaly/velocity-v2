<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Planner's booked time: a block of work on a task. Blocks the planner placed
 * itself can be replaced by planning again; blocks the user placed are pinned.
 * Working hours per weekday live on the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planner_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('pinned')->default(false); // placed or moved by the user: planning again leaves it alone
            $table->timestamps();
            $table->softDeletes();                     // so "Plan" and "Remove" can be undone
            $table->index(['user_id', 'starts_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Seven entries, Monday first: {"start":"08:00","end":"22:00"} or null for a day off. Null = the default.
            $table->json('planner_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planner_blocks');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('planner_hours'));
    }
};
