<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Capture only: a task added this way waits in the Inbox's "To sort" group, out of the
 * ranking (like a task that can't start yet), until "Rank it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('captured_at')->nullable()->after('available_from');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('captured_at'));
    }
};
