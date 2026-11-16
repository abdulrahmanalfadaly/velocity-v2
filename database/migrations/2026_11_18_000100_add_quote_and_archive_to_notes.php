<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A task made from a note's words keeps those words, so it can jump back to them.
 * An archived note is kept but read-only, out of the lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('note_task', function (Blueprint $table) {
            $table->string('quote', 300)->nullable();
        });
        Schema::table('notes', function (Blueprint $table) {
            $table->dateTime('archived_at')->nullable()->after('pinned');
        });
    }

    public function down(): void
    {
        Schema::table('note_task', fn (Blueprint $table) => $table->dropColumn('quote'));
        Schema::table('notes', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
