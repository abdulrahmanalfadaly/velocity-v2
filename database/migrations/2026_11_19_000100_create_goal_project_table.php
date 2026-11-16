<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A project can serve more than one goal. Its main goal stays in projects.goal_id
 * (it gives the project its colour); the others are here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_project', function (Blueprint $table) {
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['goal_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_project');
    }
};
