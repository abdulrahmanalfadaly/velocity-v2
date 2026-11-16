<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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

    /**
     * Page by page: ?limit= (1–200, 50 to start with) and ?cursor= from the last page's
     * meta.next. Gives one page of rows (a list or a query), with meta.count and meta.next
     * (null on the last page).
     *
     * @return array{0: Collection, 1: array{count: int, next: ?string}}
     */
    protected function page(Request $request, Collection|Builder|Relation $rows): array
    {
        $d = $request->validate(['cursor' => ['nullable', 'string', 'max:40'], 'limit' => ['nullable', 'integer', 'between:1,200']]);
        $offset = 0;
        if (isset($d['cursor'])) {
            $raw = base64_decode(strtr($d['cursor'], '-_', '+/'), true);
            if ($raw === false || ! preg_match('/^o(\d{1,9})$/', $raw, $m)) {
                throw ValidationException::withMessages(['cursor' => 'That cursor isn’t one from this list.']);
            }
            $offset = (int) $m[1];
        }
        $limit = (int) ($d['limit'] ?? 50);
        $count = $rows->count();
        $page = ! $rows instanceof Collection ? $rows->offset($offset)->limit($limit)->get() : $rows->slice($offset, $limit);
        $next = $offset + $limit < $count ? rtrim(strtr(base64_encode('o'.($offset + $limit)), '+/', '-_'), '=') : null;

        return [$page->values(), ['count' => $count, 'next' => $next]];
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
