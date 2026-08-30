<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\Rules\Password;
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
        Vite::prefetch(concurrency: 3);

        // No ->uncompromised(): that rule calls the HaveIBeenPwned API over
        // the network on every password set, which this app avoids
        // everywhere else ("nothing is transmitted anywhere"). A deployer
        // who accepts that outbound call can add it here.
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
    }
}
