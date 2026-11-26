<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Events belong to the user's own areas (user, 26 Nov 2026: "arent those supose to be based on my areas i make?")
 * instead of the fixed Classes / Work / Personal. Each existing event's old calendar is matched once to one of its
 * owner's areas where the name makes it clear (Classes → University, Uni, School, College, Study…; Work → Work, Job,
 * Internship; Personal → Personal, Life, Home); the rest get no area. The old field stays in the table, unused
 * and no longer required, so nothing is lost.
 */
return new class extends Migration
{
    private const WORDS = [
        'class' => ['university', 'uni', 'classes', 'class', 'school', 'college', 'study', 'studies', 'studying'],
        'work' => ['work', 'job', 'jobs', 'internship', 'career'],
        'personal' => ['personal', 'life', 'home'],
    ];

    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        $areas = DB::table('areas')->whereNull('deleted_at')->orderBy('position')->orderBy('id')->get(['id', 'user_id', 'name'])->groupBy('user_id');
        foreach (DB::table('events')->get(['id', 'user_id', 'calendar']) as $e) {
            $words = self::WORDS[$e->calendar] ?? [];
            $mine = $areas->get($e->user_id, collect());
            // A whole word of the area's name: "University" and "Uni stuff" match, "Unicorns" doesn't.
            $match = $mine->first(fn ($a) => in_array(mb_strtolower(trim($a->name)), $words, true))
                ?? $mine->first(fn ($a) => (bool) array_intersect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($a->name)) ?: [], $words));
            if ($match) {
                DB::table('events')->where('id', $e->id)->update(['area_id' => $match->id]);
            }
        }

        Schema::table('events', fn (Blueprint $table) => $table->string('calendar', 10)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
        });
    }
};
