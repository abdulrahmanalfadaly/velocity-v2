<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up dates, when a task started waiting, cancel with a reason, and
 * archiving finished tasks (by hand, or by itself after a number of days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('follow_up_on')->nullable()->after('waiting_for');
            $table->timestamp('waiting_since')->nullable()->after('follow_up_on');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->string('cancel_reason', 300)->nullable()->after('cancelled_at');
            $table->timestamp('archived_at')->nullable()->after('cancel_reason');
            // Set when a task is taken out of the archive, so auto-archive leaves it alone until it is finished again.
            $table->timestamp('unarchived_at')->nullable()->after('archived_at');
            $table->index(['user_id', 'follow_up_on']);
        });
        Schema::table('users', function (Blueprint $table) {
            // Finished tasks go to the archive this many days after they were finished (null = never).
            $table->unsignedSmallInteger('auto_archive_days')->nullable()->after('planner_hours');
        });
        // Tasks already waiting have been waiting since they were last changed (the best guess there is).
        DB::table('tasks')->where('status', 'waiting')->update(['waiting_since' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'follow_up_on']);
            $table->dropColumn(['follow_up_on', 'waiting_since', 'cancelled_at', 'cancel_reason', 'archived_at', 'unarchived_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_archive_days');
        });
    }
};
