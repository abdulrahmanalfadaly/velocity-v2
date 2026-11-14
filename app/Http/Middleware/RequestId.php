<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API answer carries "X-Request-Id": the one Mark sent (letters, digits
 * and dashes, 8–64 long), or a new one. Handy for matching Mark's log to an answer.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $sent = (string) $request->header('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $sent) ? $sent : (string) Str::uuid();
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
