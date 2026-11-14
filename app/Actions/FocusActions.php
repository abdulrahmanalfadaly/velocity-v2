<?php

namespace App\Actions;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Services\RankingService;
use App\Support\FocusSettings;

/**
 * Starting and stopping the focus timer, shared by the screens and the API.
 * A plain timer keeps counting past its time. A Pomodoro stops at the end of
 * each round, takes a break (short, or long after every few rounds) and then
 * waits for the next round (or starts it, if the settings say so).
 */
class FocusActions
{
    /** Shorter than this is a false start: not kept. */
    public const MIN_SECONDS = 60;

    /** A Pomodoro left alone this long after its break (or round) ended is over. */
    private const STALE_SECONDS = 2 * 3600;

    public function __construct(private readonly RankingService $ranking) {}

    /**
     * Starts focus on a task (or on nothing). A session already running is finished
     * first, and a Pomodoro break is left; the task becomes Doing.
     */
    public function start(User $user, ?Task $task, ?int $minutes, string $mode = 'timer', ?string $intention = null, ?int $round = null): FocusSession
    {
        if ($running = self::running($user)) {
            $this->finish($running);
        }
        self::leaveBreaks($user);
        $pomodoro = $mode === 'pomodoro';
        $session = $user->focusSessions()->create([
            'task_id' => $task?->id,
            'mode' => $pomodoro ? 'pomodoro' : 'timer',
            'round' => $pomodoro ? ($round ?? 1) : null,
            'intention' => $intention,
            'planned_minutes' => $pomodoro ? FocusSettings::of($user)['focus'] : $minutes,
            'started_at' => $this->ranking->now(),
            'was_status' => $task?->status,
        ]);
        if ($task && $task->isOpen() && $task->status !== Task::DOING) {
            $task->update(['status' => Task::DOING]);
        }

        return $session;
    }

    /** Ends a session and returns the seconds focused (under a minute: the session is not kept). A Pomodoro round never counts past its end. */
    public function finish(FocusSession $session): int
    {
        $now = $this->ranking->now();
        $seconds = $session->elapsed($now);
        if ($seconds < self::MIN_SECONDS) {
            $session->forceDelete();

            return $seconds;
        }
        $end = $session->paused_at ?? $now;
        if ($session->mode === 'pomodoro' && $session->planned_minutes && $seconds > $session->planned_minutes * 60) {
            $seconds = $session->planned_minutes * 60;
            $end = $session->started_at->addSeconds($seconds + $session->paused_seconds);
        }
        $session->update(['ended_at' => $end, 'seconds' => $seconds, 'paused_at' => null]);

        return $seconds;
    }

    public static function running(User $user): ?FocusSession
    {
        return FocusSession::with('task:id,title,status')->where('user_id', $user->id)->whereNull('ended_at')->latest('id')->first();
    }

    /** A Pomodoro that is between rounds (its last round, with the break that follows). */
    public static function between(User $user): ?FocusSession
    {
        return FocusSession::with('task:id,title,status')->where('user_id', $user->id)->whereNotNull('break_minutes')->latest('id')->first();
    }

    /** No Pomodoro break waits any more (a new start, "Done", or left alone too long). */
    public static function leaveBreaks(User $user): void
    {
        FocusSession::where('user_id', $user->id)->whereNotNull('break_minutes')->update(['break_minutes' => null, 'break_started_at' => null]);
    }

    /** The round is over at its planned end: kept as exactly its length, and its break is set (started at once, unless the settings say wait). */
    public function endRound(FocusSession $session, array $settings): void
    {
        $end = $session->started_at->addSeconds($session->planned_minutes * 60 + $session->paused_seconds);
        $long = $session->round && $session->round % $settings['every'] === 0;
        $session->update([
            'ended_at' => $end, 'seconds' => $session->planned_minutes * 60, 'paused_at' => null,
            'break_minutes' => $long ? $settings['long'] : $settings['short'], 'break_started_at' => $settings['autoBreak'] ? $end : null,
        ]);
    }

    /** The next round of a Pomodoro, on the same task (if it's still open) with the same intention. */
    public function nextRound(User $user, FocusSession $last): FocusSession
    {
        $task = $last->task && $last->task->isOpen() ? $last->task : null;

        return $this->start($user, $task, null, 'pomodoro', $last->intention, ($last->round ?? 0) + 1);
    }

    /**
     * The timer as the top bar shows it, moved on to where it should be now:
     * focus (a running session), or between Pomodoro rounds – roundDone (break
     * not started), break, breakOver.
     */
    public function state(User $user): ?array
    {
        $now = $this->ranking->now();
        $settings = FocusSettings::of($user);
        $extra = ['sound' => $settings['sound'], 'every' => $settings['every']];
        $running = self::running($user);
        if ($running && $running->mode === 'pomodoro' && ! $running->paused_at && $running->planned_minutes
            && $running->elapsed($now) >= $running->planned_minutes * 60) {
            $this->endRound($running, $settings);
            $running = null;
        }
        if ($running) {
            return $running->present() + $extra + ['phase' => 'focus'];
        }

        $last = self::between($user);
        if (! $last) {
            return null;
        }
        $breakEnd = $last->break_started_at?->addMinutes($last->break_minutes);
        if ($now->getTimestamp() - ($breakEnd ?? $last->ended_at)->getTimestamp() > self::STALE_SECONDS) {
            self::leaveBreaks($user);

            return null;
        }
        if (! $breakEnd) {
            return $last->present() + $extra + ['phase' => 'roundDone'];
        }
        if ($now < $breakEnd) {
            return $last->present() + $extra + ['phase' => 'break'];
        }
        // The break is over: the next round starts by itself only if the settings say so and it only just ended.
        if ($settings['autoNext'] && $now->getTimestamp() - $breakEnd->getTimestamp() <= 120) {
            $next = $this->nextRound($user, $last);
            $next->update(['started_at' => $breakEnd]);

            return $next->fresh('task')->present() + $extra + ['phase' => 'focus'];
        }

        return $last->present() + $extra + ['phase' => 'breakOver'];
    }
}
