<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use App\Services\RankingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Habit check-ins (the full Habits section comes in Phase 5). */
class HabitController extends Controller
{
    /**
     * One tap on a habit: a once-a-day habit is ticked or unticked; a habit with
     * a count (8 glasses of water) goes up by one, and back to 0 after the target.
     */
    public function check(Request $request, Habit $habit, RankingService $ranking): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $ranking->now()->timezone($user->timezone)->toDateString();
        $entry = $habit->entries()->whereDate('day', $today)->first();
        $count = $entry?->count ?? 0;
        $next = $count >= $habit->target ? 0 : $count + 1;

        if ($next === 0) {
            $entry?->delete();
        } elseif ($entry) {
            $entry->update(['count' => $next]);
        } else {
            $habit->entries()->create(['day' => $today, 'count' => $next]);
        }

        return back();
    }
}
