<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        // AuthenticateSession compares this request's session against the
        // user's current password hash on every subsequent request. Without
        // refreshing it here, the very next request from THIS browser would
        // also be logged out — only a stolen sibling session should be.
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $request->user()->getAuthPassword()
        );

        return back();
    }
}
