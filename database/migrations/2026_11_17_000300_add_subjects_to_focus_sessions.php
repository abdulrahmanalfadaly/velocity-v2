<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Focus can be on a project, a goal, a habit or a note, not only on a task (one of them,
 * or nothing). Deleting the thing for good keeps the session, without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->foreignId('habit_id')->nullable()->after('goal_id')->constrained()->nullOnDelete();
            $table->foreignId('note_id')->nullable()->after('habit_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('focus_sessions', function (Blueprint $table) {
            foreach (['project_id', 'goal_id', 'habit_id', 'note_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
        });
    }
};
