<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A reminder on each follow-up day (Settings → Tasks → Follow-ups): the time of day it comes
 * ("09:00" at first, null = off), and on each task the follow-up day it last reminded for
 * (a day moved later reminds again on the new day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('follow_up_remind_at', 5)->nullable()->default('09:00');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('follow_up_reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('follow_up_remind_at'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('follow_up_reminded_on'));
    }
};
