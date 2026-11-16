<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The energy a task needs (low / normal / high). Only a filter ("I have 30 min, low
 * energy"): it never changes a task's score or its place in the one ranking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('energy', 6)->nullable()->after('est_hours');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('energy'));
    }
};
