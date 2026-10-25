<?php

namespace App\Services;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    private StatefulGuard $guard;

    public function __construct(AuthManager $auth)
    {
        $this->guard = $auth->guard('web');
    }

    public function login(array $credentials, Request $request): void
    {
        $key = hash('sha256', $credentials['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($request));
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! $this->guard->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'active' => true,
        ], $credentials['remember'] ?? false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect. Please try again.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
    }

    public function logout(Request $request): void
    {
        $this->guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
