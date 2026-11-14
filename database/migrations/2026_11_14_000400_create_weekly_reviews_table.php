<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The weekly review: which weeks the user has looked back and ahead (Review › Weekly review). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start'); // the Monday of the week reviewed
            $table->timestamp('done_at');
            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reviews');
    }
};
