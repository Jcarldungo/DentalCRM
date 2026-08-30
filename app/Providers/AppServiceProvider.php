<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        Vite::prefetch(concurrency: 3);

        // Paired with HSTS from SecurityHeaders and SESSION_SECURE_COOKIE
        // from .env.production.example. Behind TLS termination the request
        // itself looks plaintext to PHP, so without this the signed
        // appointment-lookup links go out as http:// and then fail
        // signature validation when the recipient opens them over https://.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // No ->uncompromised(): that rule calls the HaveIBeenPwned API over
        // the network on every password set, which this app avoids
        // everywhere else ("nothing is transmitted anywhere"). A deployer
        // who accepts that outbound call can add it here.
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
    }
}
