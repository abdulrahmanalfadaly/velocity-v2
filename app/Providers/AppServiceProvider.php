<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The API: 120 requests a minute for each access key.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('key:'.($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));

        // The live (Vite) server only answers this computer. A phone on the same Wi-Fi
        // gets the built app instead, so it never waits on a server it can't reach.
        $host = request()->getHost();
        if (! app()->runningInConsole() && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            Vite::useHotFile(storage_path('framework/vite.hot.none'));
        }
    }
}
