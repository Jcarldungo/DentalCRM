<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's response headers. Before this, no response carried a single
 * one — including the staff pages that render patient records, which any
 * site could therefore frame.
 *
 * The nonce is generated before the response is produced, because Blade
 * has to stamp it onto the `@routes` blob and Vite's module tags while
 * rendering.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        // Concretely relevant here: the signed lookup URL
        // /my-appointments/{patient}?signature=… is a bearer credential
        // sitting in the address bar of a page that loads a cross-origin
        // stylesheet. This sends the origin only, never the signed path.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));

        // Only on an already-secure request: sending HSTS over plaintext
        // is ignored by browsers, and pinning it in local development
        // would make http://dentalcrm.test unreachable.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * `script-src` is nonce-based, which is cheap here because the only
     * inline script is Ziggy's `@routes` blob.
     *
     * `style-src` needs 'unsafe-inline' and that is not an oversight:
     * Inertia injects <style> elements at runtime for its progress bar
     * without a nonce, and Recharts and FullCalendar both write inline
     * `style=` attributes, which nonces cannot cover at all. Keeping
     * charting and the calendar means keeping it. Script injection is
     * blocked; style injection is not.
     */
    private function contentSecurityPolicy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];

        // Vite's dev server serves modules and the HMR websocket from its
        // own origin, so local development would otherwise render a blank
        // page with a CSP violation and no other symptom.
        //
        // The bare `ws:` scheme source rather than an origin list: on a
        // dual-stack machine Vite hands the client ws://[::1]:5173, and
        // browsers reject a bracketed IPv6 literal as a CSP source
        // outright. Local only — it never reaches a deployed response.
        if (app()->environment('local')) {
            foreach (['localhost', '127.0.0.1'] as $host) {
                $script[] = "http://{$host}:5173";
                $connect[] = "http://{$host}:5173";
            }
            $connect[] = 'ws:';
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            // data: is for FullCalendar, which embeds its navigation icon
            // font as a base64 TTF data URI — without it the calendar's
            // prev/next/today buttons render as empty boxes.
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
