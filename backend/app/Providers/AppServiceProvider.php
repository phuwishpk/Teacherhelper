<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // General API limit per user (or per IP before login). Auth endpoints use
        // the stricter throttle:10,1 in routes/api.php (DESIGN §7.4).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });
    }
}
