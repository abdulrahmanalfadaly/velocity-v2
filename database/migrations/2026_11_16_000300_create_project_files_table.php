<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Files on a project itself (a brief, a rubric), next to the files on its tasks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);      // the name it had on the user's computer
            $table->string('path', 255);      // where it is stored
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->softDeletes();            // removing a file can be undone
            $table->index(['project_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
