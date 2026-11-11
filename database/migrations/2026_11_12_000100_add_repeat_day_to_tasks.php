<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Monthly and yearly repeats keep their day of the month: a task due on the 31st
 * comes back on 28 Feb and then on 31 Mar again, instead of drifting to the 28th.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('repeat_day')->nullable()->after('repeat_every');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('repeat_day');
        });
    }
};
