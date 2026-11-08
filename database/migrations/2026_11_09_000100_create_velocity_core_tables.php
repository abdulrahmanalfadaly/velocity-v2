<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Velocity's core data: goals → projects → tasks → steps, plus the per-user
 * ranking settings and the evaluation log required by the report (Section 3.6.3).
 * Every row belongs to one user; every query is scoped to that user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Kuala_Lumpur')->after('email');
            $table->boolean('study_mode')->default(false)->after('timezone');
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('icon', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('why')->nullable();
            $table->string('target', 60)->nullable();          // "Jun 2027", "Every day"
            $table->unsignedTinyInteger('color')->default(1);  // goal colour slot 1–3 (rose, teal, olive)
            $table->string('icon', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->date('due_date')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            // A step that became a real task keeps its parent; it is ranked on its own.
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            // The four inputs of the formula (Table 3.2).
            $table->unsignedTinyInteger('importance');
            $table->unsignedTinyInteger('urgency');
            $table->dateTime('due_at')->nullable();            // stored in UTC
            $table->decimal('est_hours', 6, 2);
            // todo | doing | waiting | done | cancelled. Waiting and future
            // "available from" tasks are left out of the ranking, not scored lower.
            $table->string('status', 16)->default('todo');
            $table->string('waiting_for', 160)->nullable();
            $table->dateTime('available_from')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('score_at_completion', 5, 1)->nullable();
            $table->dateTime('reminder_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'project_id']);
        });

        Schema::create('task_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->timestamp('done_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // "Blocked": a task cannot start until every task it depends on is done.
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['task_id', 'depends_on_id']);
        });

        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);                        // created, updated, completed, reopened, deleted, restored, promoted
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'type', 'created_at']);
        });

        Schema::create('weight_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('importance', 5, 4)->default(0.42);
            $table->decimal('urgency', 5, 4)->default(0.23);
            $table->decimal('deadline', 5, 4)->default(0.23);
            $table->decimal('pressure', 5, 4)->default(0.12);
            $table->decimal('horizon_days', 4, 1)->default(7);
            $table->decimal('hours_per_day', 4, 1)->default(4);
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('detail', 120)->nullable();         // "25 min", "Mon, Wed, Fri"
            $table->json('days')->nullable();                  // ISO weekdays it is due; null = every day
            $table->unsignedSmallInteger('target')->default(1); // e.g. 8 glasses
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('habit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedSmallInteger('count')->default(1);
            $table->timestamps();
            $table->unique(['habit_id', 'day']);
        });

        // Every displayed ranking and every weight change (report Section 3.6.3).
        Schema::create('study_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['study_log', 'habit_entries', 'habits', 'notes', 'weight_profiles', 'task_events', 'task_dependencies', 'task_steps', 'tasks', 'milestones', 'projects', 'goals', 'areas'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'study_mode']);
        });
    }
};
