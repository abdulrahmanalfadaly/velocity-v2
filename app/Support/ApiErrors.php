<?php

namespace App\Support;

use App\Services\GitHubException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Every API error in one shape: {"error": {"code": "NOT_FOUND", "message": "…", "fields"?: {…}}}. */
class ApiErrors
{
    public static function render(Throwable $e): JsonResponse
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        [$status, $code, $message, $extra] = match (true) {
            $e instanceof ValidationException => [422, 'INVALID', $e->getMessage(), ['fields' => $e->errors()]],
            $e instanceof GitHubException => [$status, 'GITHUB', $e->getMessage(), []],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Send a valid access key: "Authorization: Bearer <key>". Keys are made in Settings → Integrations.', []],
            $e instanceof ThrottleRequestsException => [429, 'TOO_MANY_REQUESTS', 'Too many requests. Wait a minute and try again.', []],
            $status === 404 => [404, 'NOT_FOUND', self::message($e, 'Not found.'), []],
            $status === 405 => [405, 'METHOD_NOT_ALLOWED', 'That method isn’t allowed here.', []],
            $status === 403 => [403, 'FORBIDDEN', self::message($e, 'Not allowed.'), []],
            $status === 422 => [422, 'INVALID', self::message($e, 'That request isn’t valid.'), []],
            $status < 500 => [$status, 'REQUEST_FAILED', self::message($e, 'Request failed.'), []],
            default => [500, 'SERVER_ERROR', 'Something went wrong in Velocity.', []],
        };
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status, $headers);
    }

    /** Our own abort() messages are meant for the caller; Laravel's internal ones ("No query results for model …") aren't. */
    private static function message(Throwable $e, string $fallback): string
    {
        $m = $e->getMessage();

        return $m === '' || str_contains($m, 'No query results') || str_contains($m, 'could not be found') ? $fallback : $m;
    }
}
