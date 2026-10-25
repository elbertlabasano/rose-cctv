<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Keycloak\Provider;
use Symfony\Component\HttpFoundation\RedirectResponse;

class KeycloakAuthenticationService
{
    public const LOGIN_ERROR = 'Rose sign-in could not be completed. Try again, or ask an administrator to check your Employee ID code and account access.';

    public function redirect(Request $request): RedirectResponse
    {
        $this->assertConfigured();
        $request->session()->put('rose_sso_started_at', now()->timestamp);

        return $this->provider()->redirect();
    }

    public function login(Request $request): void
    {
        try {
            $this->assertConfigured();
            $started = $request->session()->pull('rose_sso_started_at');
            $code = $request->query('code');
            $verifier = $request->session()->get('code_verifier');
            if (! is_int($started) || $started > now()->timestamp || now()->timestamp - $started > 600
                || ! is_string($code) || $code === '' || strlen($code) > 4096
                || ! is_string($verifier) || strlen($verifier) < 43 || strlen($verifier) > 128
                || $request->has('error')) {
                $this->fail('invalid_or_expired_handshake');
            }

            // Socialite validates and consumes session state before exchanging the code.
            // The adapter obtains these claims from authenticated HTTPS UserInfo.
            $claims = $this->provider()->user()->getRaw();
            $subject = $claims['sub'] ?? null;
            $idcode = $claims[config('sso.idcode_claim')] ?? null;
            if (! $this->validIdentifier($subject, 191) || ! $this->validIdentifier($idcode, 100)) {
                $this->fail('missing_or_invalid_identity_claim');
            }

            $issuer = rtrim(config('services.keycloak.base_url'), '/').'/realms/'.config('services.keycloak.realms');
            $user = DB::transaction(function () use ($issuer, $subject, $idcode) {
                $user = User::where('keycloak_issuer', $issuer)->where('keycloak_subject', $subject)->lockForUpdate()->first();
                if ($user) {
                    // A code change never reassigns an established identity to another account.
                    if ($user->keycloak_issuer !== $issuer || $user->keycloak_subject !== $subject
                        || ! $user->active || $user->idcode !== $idcode) {
                        $this->fail('linked_identity_mismatch_or_inactive');
                    }

                    return $user;
                }

                $matches = User::where('idcode', $idcode)->lockForUpdate()->limit(2)->get();
                if ($matches->count() !== 1) {
                    $this->fail('employee_code_match_not_unique');
                }
                $user = $matches->first();
                if (! $user->active || $user->idcode !== $idcode
                    || $user->keycloak_issuer !== null || $user->keycloak_subject !== null) {
                    $this->fail('account_inactive_or_identity_conflict');
                }
                $user->forceFill(['keycloak_issuer' => $issuer, 'keycloak_subject' => $subject])->save();

                return $user;
            });

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        } finally {
            $this->clearHandshake($request);
        }
    }

    public function clearHandshake(Request $request): void
    {
        $request->session()->forget(['rose_sso_started_at', 'state', 'code_verifier']);
    }

    private function provider(): Provider
    {
        // Do not reuse a provider's cached user or request between login attempts.
        Socialite::forgetDrivers();

        return Socialite::driver('keycloak')->scopes(['openid', 'profile', 'email'])->enablePKCE();
    }

    private function validIdentifier(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $max
            && preg_match('/[\s\p{C}]/u', $value) === 0;
    }

    private function assertConfigured(): void
    {
        $config = config('services.keycloak');
        if (! config('sso.enabled') || ! is_array($config)) {
            $this->fail('sso_not_enabled');
        }
        foreach (['client_id', 'client_secret', 'redirect', 'base_url', 'realms'] as $field) {
            if (! is_string($config[$field] ?? null) || $config[$field] === '') {
                $this->fail('missing_configuration');
            }
        }
        $base = parse_url($config['base_url']);
        $redirect = parse_url($config['redirect']);
        if (! $base || ($base['scheme'] ?? '') !== 'https' || empty($base['host'])
            || isset($base['user']) || isset($base['pass']) || isset($base['query']) || isset($base['fragment'])
            || ! preg_match('/^[a-zA-Z0-9._-]+$/D', $config['realms'])
            || strlen(rtrim($config['base_url'], '/').'/realms/'.$config['realms']) > 191
            || ! $redirect || empty($redirect['host']) || isset($redirect['user']) || isset($redirect['pass'])
            || isset($redirect['query']) || isset($redirect['fragment'])
            || ! (($redirect['scheme'] ?? '') === 'https'
                || (($redirect['scheme'] ?? '') === 'http' && app()->environment('local', 'testing')
                    && in_array($redirect['host'], ['127.0.0.1', 'localhost'], true)))
            || ! $this->validIdentifier(config('sso.idcode_claim'), 100)) {
            $this->fail('invalid_configuration');
        }
    }

    private function fail(string $reason): never
    {
        Log::warning('Rose SSO check failed.', ['failure_code' => $reason]);
        throw ValidationException::withMessages(['sso' => self::LOGIN_ERROR]);
    }
}
