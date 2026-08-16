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

        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Sanctum token expiry (7 days) is set in sanctum config
        // Additional service bindings can go here
    }
}
