<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A note's links to other notes, goals and habits (tasks and people keep their own tables). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 8); // note, goal, habit
            $table->unsignedBigInteger('linkable_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['note_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_links');
    }
};
