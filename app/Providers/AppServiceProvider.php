<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Production hardening that must hold even if the environment file is
        // misconfigured (D8/D17): never leak debug output, and only send the
        // session/CSRF cookies over HTTPS. Local and testing environments keep
        // their configured behavior so development is unaffected.
        if ($this->app->environment('production')) {
            config([
                'app.debug' => false,
                'session.secure' => true,
            ]);
        }

        // Named rate limiter for user login attempts.
        // Provides multi-tiered brute-force and abuse protection:
        // 1. Account + IP (5/min): prevents targeted password guessing on a single account from an IP.
        // 2. Account-level (10/min): protects against distributed credential guessing across multiple IPs.
        // 3. IP-level (30/min): protects against credential stuffing/spraying across accounts from one IP,
        //    while allowing shared NAT / campus / office environments to function without false lockouts.
        RateLimiter::for('login', function (Request $request) {
            $rawEmail = $request->input('email');
            $email = is_string($rawEmail) ? Str::transliterate(Str::lower(trim($rawEmail))) : '';
            $ip = (string) $request->ip();

            $accountKey = $email !== '' ? 'account:'.$email : 'missing_email:'.$ip;
            $accountIpKey = ($email !== '' ? $email : 'missing_email').'|'.$ip;

            return [
                Limit::perMinute(5)->by($accountIpKey),
                Limit::perMinute(10)->by($accountKey),
                Limit::perMinute(30)->by($ip),
            ];
        });
    }
}
