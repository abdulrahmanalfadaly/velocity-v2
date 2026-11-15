<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Templates with dates counted from the day they are used: "due 3 days after use, at 17:00", "can start the next day". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_days')->nullable()->after('est_hours');
            $table->string('due_time', 5)->nullable()->after('due_days'); // "17:00"; none = the end of that day
            $table->unsignedSmallInteger('start_days')->nullable()->after('due_time');
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(['due_days', 'due_time', 'start_days']);
        });
    }
};
