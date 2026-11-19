<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A task's log: entries the user writes about the task (what they did, what happened),
 * each with any files attached to it. Files on an entry are ordinary task files that
 * also point at the entry (log_id); files without one are the task's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable(); // empty when the entry is only files
            $table->timestamps();
            $table->softDeletes();            // removing an entry can be undone
            $table->index(['task_id', 'deleted_at']);
        });
        Schema::table('task_files', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->nullable()->after('task_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->dropIndex(['log_id']);
            $table->dropColumn('log_id');
        });
        Schema::dropIfExists('task_logs');
    }
};
