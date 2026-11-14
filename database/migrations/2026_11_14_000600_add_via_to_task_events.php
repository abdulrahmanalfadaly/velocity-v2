<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A change made through the API remembers the access key's name ("via Mark"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_events', function (Blueprint $table) {
            $table->string('via', 60)->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('task_events', function (Blueprint $table) {
            $table->dropColumn('via');
        });
    }
};
