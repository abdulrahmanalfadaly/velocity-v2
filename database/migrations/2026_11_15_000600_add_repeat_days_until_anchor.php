<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeats: several weekdays ("every Mon and Thu"), a last day, and the date a
 * copy was meant for — so moving one copy doesn't move the ones after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('repeat_days')->nullable()->after('repeat_every'); // ISO weekdays, for weekly repeats
            $table->date('repeat_until')->nullable()->after('repeat_days'); // no copy after this day
            $table->dateTime('repeat_anchor')->nullable()->after('repeat_until'); // when this copy was meant to be due
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['repeat_days', 'repeat_until', 'repeat_anchor']);
        });
    }
};
