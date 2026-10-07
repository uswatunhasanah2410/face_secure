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
        // Tiap limiter punya penghitung sendiri (per IP), jadi tidak saling mengganggu.
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(5)->by('register|' . $r->ip()));
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by('otp-verify|' . $r->ip()));
        RateLimiter::for('otp-resend', fn (Request $r) => Limit::perMinute(3)->by('otp-resend|' . $r->ip()));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(15)->by('password-reset|' . $r->ip()));
        RateLimiter::for('face', fn (Request $r) => Limit::perMinute(10)->by('face|' . $r->ip()));
    }
}
