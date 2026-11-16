<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A note can be in more than one project: notes.project_id stays its first one (new
 * tasks made from it go there), note_project holds the others. A note can also sit in
 * an area (University, Work …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_project', function (Blueprint $table) {
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['note_id', 'project_id']);
        });
        Schema::table('notes', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notes', fn (Blueprint $table) => $table->dropConstrainedForeignId('area_id'));
        Schema::dropIfExists('note_project');
    }
};
