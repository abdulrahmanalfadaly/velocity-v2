<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Memories: dated things to remember about a person ("Loves matcha", "Hiking trip, Aug 2025"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('text', 500);
            $table->date('on')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['person_id', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_memories');
    }
};
