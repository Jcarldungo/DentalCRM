<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                // An explicit projection, not the whole model. Sharing
                // $request->user() means "whatever columns users has", so
                // the day someone adds two_factor_secret or an api_token
                // it is serialized into the data-page attribute of every
                // rendered page with no code change to review.
                // email_verified_at is here because the profile page's
                // "resend verification email" prompt keys off it. Add a
                // field only when a page genuinely needs it.
                'user' => $request->user()?->only('id', 'name', 'email', 'email_verified_at'),
            ],
            // One-shot feedback for the toast in AuthenticatedLayout. Kept
            // to a fixed shape so a page can't be handed arbitrary keys.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
