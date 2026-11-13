<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Classes and events on the Planner. A weekly event (a class) is one row that repeats
 * every week until an end date; single weeks can be skipped (a holiday). Times are
 * stored in UTC; the weekly repeat is worked out in the user's own time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('calendar', 10);                  // class | work | personal
            $table->string('title', 160);
            $table->string('location', 160)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('all_day')->default(false);
            $table->dateTime('starts_at');                   // the first time it happens
            $table->dateTime('ends_at');
            $table->boolean('repeat_weekly')->default(false);
            $table->date('repeat_until')->nullable();        // last week it happens (the user's date)
            $table->json('skipped')->nullable();             // dates of single weeks that don't happen
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
