<?php

namespace App\Actions;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Services\RankingService;

/** Starting and stopping the focus timer, shared by the screens and the API. */
class FocusActions
{
    /** Shorter than this is a false start: not kept. */
    public const MIN_SECONDS = 60;

    public function __construct(private readonly RankingService $ranking) {}

    /** Starts focus on a task (or on nothing). A session already running is finished first; the task becomes Doing. */
    public function start(User $user, ?Task $task, ?int $minutes): FocusSession
    {
        if ($running = self::running($user)) {
            $this->finish($running);
        }
        $session = $user->focusSessions()->create([
            'task_id' => $task?->id,
            'planned_minutes' => $minutes,
            'started_at' => $this->ranking->now(),
            'was_status' => $task?->status,
        ]);
        if ($task && $task->isOpen() && $task->status !== Task::DOING) {
            $task->update(['status' => Task::DOING]);
        }

        return $session;
    }

    /** Ends a session and returns the seconds focused (under a minute: the session is not kept). */
    public function finish(FocusSession $session): int
    {
        $now = $this->ranking->now();
        $seconds = $session->elapsed($now);
        if ($seconds < self::MIN_SECONDS) {
            $session->delete();

            return $seconds;
        }
        $session->update(['ended_at' => $session->paused_at ?? $now, 'seconds' => $seconds, 'paused_at' => null]);

        return $seconds;
    }

    public static function running(User $user): ?FocusSession
    {
        return FocusSession::with('task:id,title')->where('user_id', $user->id)->whereNull('ended_at')->latest('id')->first();
    }
}
