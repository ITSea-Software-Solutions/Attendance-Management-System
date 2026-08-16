<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Named rate limiters — separate buckets so signup bursts can never
        // lock out logins (and vice versa). Both keyed per IP.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('signup', fn (Request $r) => Limit::perMinutes(10, 3)->by($r->ip()));

        // Force HTTPS for generated URLs ONLY when the app is actually served
        // over https (APP_URL scheme is the source of truth). Forcing it while
        // serving plain http (current demo droplet) breaks every generated URL
        // — e.g. worker photo routes — by pointing them at a TLS port nothing
        // listens on.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Sanctum token expiry (7 days) is set in sanctum config
        // Additional service bindings can go here
    }
}
