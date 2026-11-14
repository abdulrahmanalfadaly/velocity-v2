<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Areas can be removed (with Undo); projects get a status (planned, active,
 * on hold, completed); goals a status, a real target date and a way to count
 * progress (from tasks, from milestones, or by hand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('status', 10)->default('active')->after('description'); // planned, active, on_hold, completed
            $table->dateTime('completed_at')->nullable()->after('status');
        });
        Schema::table('goals', function (Blueprint $table) {
            $table->string('status', 10)->default('active')->after('why'); // active, paused, achieved, dropped
            $table->date('target_date')->nullable()->after('target');
            $table->string('progress_mode', 10)->default('tasks')->after('target_date'); // tasks, milestones, manual
            $table->unsignedTinyInteger('progress')->nullable()->after('progress_mode'); // manual: 0–100
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn(['status', 'target_date', 'progress_mode', 'progress']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['status', 'completed_at']);
        });
        Schema::table('areas', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
