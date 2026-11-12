<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A template is a task to copy from: title, scores, hours, project, repeat and its steps. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('importance')->default(3);
            $table->unsignedTinyInteger('urgency')->default(3);
            $table->decimal('est_hours', 6, 2)->default(1);
            $table->string('repeat_unit', 8)->nullable();
            $table->unsignedTinyInteger('repeat_every')->default(1);
            $table->json('steps'); // list of step titles
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
