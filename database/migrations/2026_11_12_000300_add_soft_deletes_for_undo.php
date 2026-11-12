<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Removing a step, a milestone or a template can be undone, so they are only marked deleted. */
return new class extends Migration
{
    private const TABLES = ['task_steps', 'milestones', 'task_templates'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
