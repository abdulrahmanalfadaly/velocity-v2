<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Habits: an end date (starts_on now works for every schedule: before it and after
 * ends_on the habit isn't due), a description and a "why", goals (more than one)
 * and an area. Routines: a description, and archiving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->text('description')->nullable()->after('detail');
            $table->text('why')->nullable()->after('description');
            $table->foreignId('area_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
        Schema::create('goal_habit', function (Blueprint $table) {
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->primary(['goal_id', 'habit_id']);
        });
        Schema::table('routines', function (Blueprint $table) {
            $table->string('description', 300)->nullable()->after('name');
            $table->dateTime('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routines', fn (Blueprint $table) => $table->dropColumn(['description', 'archived_at']));
        Schema::dropIfExists('goal_habit');
        Schema::table('habits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn(['ends_on', 'description', 'why']);
        });
    }
};
