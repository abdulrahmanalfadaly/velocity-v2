<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Files attached to tasks. The file itself lives in storage/app/private/task-files
 * (never public, never in Git); this row knows its name, type and size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);      // the name it had on the user's computer
            $table->string('path', 255);      // where it is stored
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->softDeletes();            // removing a file can be undone
            $table->index(['task_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_files');
    }
};
