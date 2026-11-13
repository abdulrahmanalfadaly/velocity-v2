<?php

namespace App\Http\Middleware;

use App\Http\Controllers\FocusController;
use App\Services\NavData;
use App\Support\Clock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'timezone' => $user->timezone,
                    'fypMode' => (bool) $user->fyp_mode,
                    'email_verified_at' => $user->email_verified_at,
                ] : null,
            ],
            // The server's "now" (honours VELOCITY_NOW), so dates read the same everywhere.
            'clock' => fn () => [
                'now' => app(Clock::class)->now()->toIso8601ZuluString(),
                'tz' => $user?->timezone ?? config('velocity.timezone'),
                'frozen' => config('velocity.fixed_now') !== null,
            ],
            'nav' => fn () => $user && ! $user->fyp_mode ? app(NavData::class)->for($user) : null,
            // The focus session that is running (personal mode only), drawn as the timer in the top bar.
            'focusSession' => fn () => $user && ! $user->fyp_mode ? FocusController::running($user)?->present() : null,
            'flash' => fn () => [
                'toast' => $request->session()->get('toast'),
                'undo' => $request->session()->get('undo'),
                'focusDone' => $request->session()->get('focusDone'),
            ],
        ];
    }
}
