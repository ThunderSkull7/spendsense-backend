<?php

namespace App\Providers;

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
    }
}
