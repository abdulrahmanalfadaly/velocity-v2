<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Repeating tasks. Finishing one creates the next copy with the next due date,
 * so every finished copy stays in the history as its own done task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('repeat_unit', 8)->nullable()->after('reminder_at');          // day | weekday | week | month | year
            $table->unsignedTinyInteger('repeat_every')->default(1)->after('repeat_unit'); // every 2 weeks, every 3 days …
            $table->unsignedBigInteger('repeated_from_id')->nullable()->after('repeat_every')->index(); // the copy this one follows
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['repeated_from_id']);
            $table->dropColumn(['repeat_unit', 'repeat_every', 'repeated_from_id']);
        });
    }
};
