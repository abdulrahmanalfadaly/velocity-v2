<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When a task's reminder was shown, so it pops up once (snoozing sets a new time and clears this). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('reminder_sent_at')->nullable()->after('reminder_at');
            $table->index(['user_id', 'reminder_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'reminder_at']);
            $table->dropColumn('reminder_sent_at');
        });
    }
};
