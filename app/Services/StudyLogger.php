<?php

namespace App\Services;

use App\Models\StudyLog;
use App\Models\User;

/**
 * The evaluation log (report Section 3.6.3): every ranking a student sees and
 * every weight change, so ranking agreement can be analysed afterwards.
 * Only accounts in FYP mode are recorded.
 */
class StudyLogger
{
    public function ranking(User $user, RankedList $list, string $screen): void
    {
        if (! $this->records($user)) {
            return;
        }
        StudyLog::create([
            'user_id' => $user->id,
            'kind' => 'ranking_view',
            'payload' => [
                'screen' => $screen,
                'weights' => $list->params->weights->toPercentages(),
                'ranking' => array_map(fn ($s) => ['id' => (int) $s->task->id, 'rank' => $s->rank, 'score' => $s->displayScore()], $list->scored),
            ],
        ]);
    }

    public function weights(User $user, array $before, array $after): void
    {
        if (! $this->records($user)) {
            return;
        }
        StudyLog::create(['user_id' => $user->id, 'kind' => 'weights_change', 'payload' => ['before' => $before, 'after' => $after]]);
    }

    private function records(User $user): bool
    {
        return config('velocity.study_log') && $user->fyp_mode;
    }
}
