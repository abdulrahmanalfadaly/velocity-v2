<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Screens that are not part of the FYP. An account in FYP mode is sent to My tasks instead. */
class PersonalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->fyp_mode) {
            abort_if($request->expectsJson(), 404);

            return redirect()->route('tasks.index');
        }

        return $next($request);
    }
}
