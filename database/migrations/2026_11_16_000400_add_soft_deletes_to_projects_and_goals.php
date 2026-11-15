<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deleting a project or a goal that still has things in it can be undone, so they are only marked deleted. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'goals'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (['projects', 'goals'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
