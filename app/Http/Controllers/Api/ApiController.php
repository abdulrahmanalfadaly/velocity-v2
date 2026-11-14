<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API for Mark (and anything else holding an access key). Answers are
 * `{data, meta?}`; errors `{error: {code, message, fields?}}` (see bootstrap/app.php).
 */
abstract class ApiController extends Controller
{
    public function __construct(protected readonly RankingService $ranking) {}

    protected function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    /** The start of the user's own today. */
    protected function today(User $user): CarbonImmutable
    {
        return $this->ranking->now()->timezone($user->timezone)->startOfDay();
    }

    /** Whether the key may use this area ("notes:read"). */
    protected function can(Request $request, string $ability): bool
    {
        return (bool) $request->user()?->tokenCan($ability);
    }

    /**
     * A time sent in the user's own time zone ("2026-11-20 17:00", or with an offset),
     * stored in UTC. A plain date for a due date means the end of that day.
     */
    protected function at(?string $value, User $user, bool $endOfDay = false): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59';
        }

        return $this->fromLocal($value, $user);
    }
}
