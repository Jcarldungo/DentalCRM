<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // First in the group: it generates the CSP nonce that Blade
            // stamps onto @routes and Vite's module tags, so it has to run
            // before anything renders.
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);

        /*
         * Behind a load balancer with no trusted proxies, request()->ip()
         * is the balancer's address for every request — so all four
         * throttle limiters and LoginRequest's per-IP bucket collapse into
         * one global bucket, and six requests from anyone locks the whole
         * clinic out. isSecure() also returns false behind TLS
         * termination, which makes URL::signedRoute() emit http:// links
         * that then fail signature validation when opened over https://.
         *
         * Defaults to none, which is correct for anyone not behind a
         * proxy; a deployer behind one must set TRUSTED_PROXIES.
         */
        $proxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? null;
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : ($proxies ? explode(',', (string) $proxies) : null),
        );

        /*
         * Without this the Host header is never validated and Laravel
         * derives absolute URLs from it, so on a server with a catch-all
         * vhost an attacker can POST /forgot-password with
         * Host: evil.test and have a real reset token for a real staff
         * account mailed to that account pointing at their domain. The
         * appointment-lookup mail has the same shape.
         *
         * A closure, not an array: this file is evaluated before the
         * environment file is loaded, so config() is not yet available.
         * Laravel's own TrustHosts skips local and testing environments,
         * which is why Herd hosts and the test client are unaffected.
         */
        $middleware->trustHosts(at: fn () => array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
        ]));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
