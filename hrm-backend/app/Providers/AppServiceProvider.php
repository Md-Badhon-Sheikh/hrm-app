<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Live site runs on https: make asset()/url() links (app icons,
        // attachments) https too, even behind a proxy/CDN. Local http
        // (APP_URL=http://...) is unaffected.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Per-route, per-IP limits for the public auth endpoints.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip().'|'.$r->path().'|'.$r->input('email')));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}
