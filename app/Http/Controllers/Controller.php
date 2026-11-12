<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /** Every record belongs to one user: anyone else gets a 404, never a hint it exists. */
    protected function own(Model $model, User $user, string $key = 'user_id'): Model
    {
        abort_unless((int) $model->getAttribute($key) === (int) $user->id, 404);

        return $model;
    }

    /**
     * What the toast's Undo button should call: an action name the front end maps to
     * a route (see resources/js/components/velocity/toast.tsx), the record's id and
     * any data to send with it.
     */
    protected function undo(string $action, int $id, array $data = []): array
    {
        return ['action' => $action, 'id' => $id, 'data' => $data ?: null];
    }

    /** A date and time typed in the user's own time zone, stored in UTC. */
    protected function fromLocal(?string $value, User $user): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, $user->timezone)->utc() : null;
    }
}
