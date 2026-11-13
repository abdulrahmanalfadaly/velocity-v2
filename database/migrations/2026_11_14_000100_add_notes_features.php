<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Notes (Phase 4): the editor's text, the journal (one entry a day), notes for a
 * class on a date, links to tasks, and files inside notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            // body holds the editor's document (JSON); body_text the same words as plain text, for search and previews.
            $table->longText('body_text')->nullable()->after('body');
            $table->foreignId('event_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->date('event_date')->nullable()->after('event_id');   // which class: the event and the day it happened
            $table->date('journal_date')->nullable()->after('pinned');   // a journal entry: the day it is for
            $table->unique(['user_id', 'journal_date']);
            $table->unique(['user_id', 'event_id', 'event_date']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('note_task', function (Blueprint $table) {
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['note_id', 'task_id']);
            $table->index('task_id');
        });

        // The file itself lives in storage/app/private/note-files/<user> (never public, never in Git).
        Schema::create('note_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('path', 255);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->boolean('inline')->default(false); // a picture placed in the text, not listed under Files
            $table->timestamps();
            $table->softDeletes();
            $table->index(['note_id', 'deleted_at']);
        });

        // Notes written before the editor were plain text: turn each into paragraphs.
        DB::table('notes')->orderBy('id')->each(function ($n) {
            $text = (string) $n->body;
            if (str_starts_with(ltrim($text), '{')) {
                return;
            }
            $paragraphs = array_map(
                fn ($line) => $line === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]],
                $text === '' ? [] : preg_split('/\R/', $text),
            );
            DB::table('notes')->where('id', $n->id)->update([
                'body' => json_encode(['type' => 'doc', 'content' => $paragraphs ?: [['type' => 'paragraph']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'body_text' => trim($text),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_files');
        Schema::dropIfExists('note_task');
        Schema::table('notes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'journal_date']);
            $table->dropUnique(['user_id', 'event_id', 'event_date']);
            $table->dropIndex(['user_id', 'updated_at']);
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn(['body_text', 'event_date', 'journal_date']);
        });
    }
};
