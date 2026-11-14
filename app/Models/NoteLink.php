<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note linked to another note, a goal or a habit. The other side lists it as "linked from". */
class NoteLink extends Model
{
    public const TYPES = ['note', 'goal', 'habit'];

    public const UPDATED_AT = null;

    protected $fillable = ['linkable_type', 'linkable_id'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /** Notes (of this user, not deleted) linked to a goal, habit or note, newest edit first. */
    public static function notesFor(User $user, string $type, int $id): array
    {
        return Note::where('user_id', $user->id)
            ->whereIn('id', self::where('linkable_type', $type)->where('linkable_id', $id)->select('note_id'))
            ->with('event')->latest('updated_at')->limit(50)->get()
            ->map(fn (Note $n) => ['id' => $n->id, 'title' => $n->displayTitle(), 'updatedAt' => $n->updated_at?->toIso8601ZuluString()])->values()->all();
    }
}
