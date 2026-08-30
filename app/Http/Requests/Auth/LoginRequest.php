<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), 60);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * Two independent buckets: five attempts per email+IP (unchanged), and
     * twenty attempts per IP regardless of which email is targeted — the
     * email+IP bucket alone lets one IP spray many accounts without ever
     * tripping a single account's limiter (§A4).
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $seconds = null;

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
        }

        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), 20)) {
            $seconds = max($seconds ?? 0, RateLimiter::availableIn($this->ipThrottleKey()));
        }

        if ($seconds === null) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Get the IP-only rate limiting throttle key for the request.
     *
     * Not cleared on success — a shared-IP clinic where one staff member
     * logs in successfully must not reset an attacker's per-IP budget.
     */
    public function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }
}
