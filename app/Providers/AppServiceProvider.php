<?php

namespace App\Providers;

use App\Support\SyncState;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        // Slow down credential stuffing against the login endpoint: five
        // attempts per minute per email + IP combination.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower($request->input('email', '')).'|'.$request->ip(),
            );
        });

        // Live sync: any saved/deleted record marks its module as changed so
        // the web system and the mobile app refresh it within seconds.
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *'], function (string $event, array $payload) {
            SyncState::bumpModel($payload[0]);
        });
    }
}
