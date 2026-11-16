<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Booked time can be for a task, for a whole project ("FYP, Thu 2–5 pm") or reserved
 * with a label ("Gym", "Keep free"); planning never books over any of them. A locked
 * block asks before it is moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planner_blocks', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->change();
            $table->string('kind', 10)->default('task')->after('task_id'); // task, project, reserved
            $table->foreignId('project_id')->nullable()->after('kind')->constrained()->cascadeOnDelete();
            $table->string('label', 80)->nullable()->after('project_id');
            $table->boolean('locked')->default(false)->after('pinned');
        });
    }

    public function down(): void
    {
        Schema::table('planner_blocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['kind', 'label', 'locked']);
        });
    }
};
