<?php

namespace App\Http\Middleware;

use App\Support\ApiAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `scope:tasks:write` – the access key must allow every area named. */
class ApiScope
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        foreach ($abilities as $ability) {
            if (! $request->user()?->tokenCan($ability)) {
                return response()->json(['error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'This key needs '.ApiAccess::label($ability).'.',
                ]], 403);
            }
        }

        return $next($request);
    }
}
