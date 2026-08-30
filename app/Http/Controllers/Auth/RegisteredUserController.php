<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $this->abortIfRegistrationClosed();

        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): SymfonyResponse
    {
        $this->abortIfRegistrationClosed();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'registration_code' => ['required', 'string'],
        ]);

        if (! hash_equals((string) config('clinic.registration_code'), $request->string('registration_code')->value())) {
            throw ValidationException::withMessages([
                'registration_code' => 'That registration code is not correct.',
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        // A full page load, not a client-side redirect — see
        // AuthenticatedSessionController::store() for why.
        return Inertia::location(route('dashboard'));
    }

    /**
     * Self-registration is a deployment-time choice, not a permanent
     * feature — blanking the config value turns it off without a
     * deploy of route changes.
     *
     * @throws HttpException
     */
    protected function abortIfRegistrationClosed(): void
    {
        abort_if(blank(config('clinic.registration_code')), 403);
    }
}
