<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Settings → Tasks → "Archive finished tasks": done and cancelled tasks go to
 * the archive a number of days after they were finished. Run when the lists
 * that show finished tasks open. A task taken out of the archive by hand is
 * left alone until it is finished again.
 */
class AutoArchive
{
    /** The choices in Settings (days). */
    public const DAYS = [1, 7, 30];

    public static function run(User $user, CarbonImmutable $now): int
    {
        if (! $user->auto_archive_days) {
            return 0;
        }
        $cutoff = $now->utc()->subDays($user->auto_archive_days)->toDateTimeString();

        return $user->tasks()->whereIn('status', [Task::DONE, Task::CANCELLED])->whereNull('archived_at')
            ->whereRaw('coalesce(completed_at, cancelled_at) <= ?', [$cutoff])
            ->where(fn ($q) => $q->whereNull('unarchived_at')->orWhereRaw('unarchived_at < coalesce(completed_at, cancelled_at)'))
            ->update(['archived_at' => $now->utc()->toDateTimeString()]);
    }
}
