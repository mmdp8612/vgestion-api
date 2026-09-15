<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(120)->by(
                (string) $request->attributes->get('broker_id', $request->ip())
            ),
        ]);

        RateLimiter::for('consultas', fn (Request $request) => [
            Limit::perMinute(10)->by(
                (string) $request->attributes->get('broker_id', 'sin-broker').'|'.$request->ip()
            ),
            Limit::perHour(100)->by(
                (string) $request->attributes->get('broker_id', 'sin-broker').'|'.$request->ip()
            ),
        ]);
    }
}
