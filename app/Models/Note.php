<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A note: free text in the editor, optionally in a project, linked to tasks, for a
 * class on a date (event_id + event_date) or a journal entry (journal_date).
 * The body is the editor's document as JSON; body_text the same words as plain text.
 */
class Note extends Model
{
    use SoftDeletes;

    protected $fillable = ['project_id', 'event_id', 'event_date', 'journal_date', 'title', 'body', 'body_text', 'pinned'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'event_date' => 'immutable_date', 'journal_date' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        // Rows of note_files go with the note in the database; their stored files must go too.
        static::forceDeleting(fn (Note $n) => $n->files()->withTrashed()->get()->each->forceDelete());
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withPivot('created_at')->orderByPivot('created_at');
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_note')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(NoteFile::class);
    }

    /** Nothing written, nothing attached or linked: safe to throw away when the user leaves it. */
    public function scopeBlank(Builder $q): void
    {
        $q->where('title', '')->where(fn ($q) => $q->whereNull('body_text')->orWhere('body_text', ''))
            ->doesntHave('files')->doesntHave('tasks')->doesntHave('people');
    }

    /** The name shown everywhere: the title, or what the note is for. */
    public function displayTitle(): string
    {
        if (trim($this->title) !== '') {
            return $this->title;
        }
        if ($this->journal_date) {
            return $this->journal_date->format('l j F Y');
        }
        if ($this->event_date) {
            return ($this->event?->title ?? 'Class').' · '.$this->event_date->format('D j M');
        }

        return 'Untitled';
    }

    /** The first words, for lists. */
    public function preview(): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $this->body_text)), 160);
    }

    /** The editor's document. Old plain-text bodies become paragraphs. */
    public function doc(): array
    {
        $doc = json_decode((string) $this->body, true);

        return is_array($doc) && ($doc['type'] ?? null) === 'doc' ? $doc : self::docFromText((string) $this->body);
    }

    public static function docFromText(string $text): array
    {
        $lines = $text === '' ? [] : preg_split('/\R/', $text);
        $content = array_map(fn ($l) => $l === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $l]]], $lines);

        return ['type' => 'doc', 'content' => $content ?: [['type' => 'paragraph']]];
    }

    /** The words of a document as plain text: one line per paragraph, heading, list item … */
    public static function textOf(array $node): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        $inner = implode('', array_map(fn ($c) => is_array($c) ? self::textOf($c) : '', $node['content'] ?? []));
        // Lists and quotes hold paragraphs, which end their own lines.
        $block = in_array($type, ['paragraph', 'heading', 'codeBlock', 'horizontalRule'], true);
        $text = $block ? $inner."\n" : $inner;

        return $type === 'doc' ? trim(preg_replace("/\n{3,}/", "\n\n", $text)) : $text;
    }
}
