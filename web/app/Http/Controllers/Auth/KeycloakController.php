<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\KeycloakAuthenticationService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class KeycloakController extends Controller
{
    public function redirect(Request $request, KeycloakAuthenticationService $sso): RedirectResponse
    {
        try {
            return $sso->redirect($request);
        } catch (Throwable $exception) {
            return $this->failed($request, $sso, $exception);
        }
    }

    public function callback(Request $request, KeycloakAuthenticationService $sso): RedirectResponse
    {
        try {
            $sso->login($request);

            return redirect('/dashboard');
        } catch (Throwable $exception) {
            return $this->failed($request, $sso, $exception);
        }
    }

    private function failed(Request $request, KeycloakAuthenticationService $sso, Throwable $exception): RedirectResponse
    {
        $sso->clearHandshake($request);
        if (! $exception instanceof ValidationException) {
            // HTTP exception messages and response bodies can contain credentials or tokens.
            $context = ['exception_class' => $exception::class];
            if ($exception instanceof ConnectException || $exception instanceof RequestException) {
                $errno = $exception->getHandlerContext()['errno'] ?? null;
                if (is_int($errno)) {
                    $context['curl_errno'] = $errno;
                }
            }
            if ($exception instanceof RequestException && $exception->hasResponse()) {
                $context['http_status'] = $exception->getResponse()->getStatusCode();
            }
            Log::warning('Rose SSO sign-in failed.', $context);
        }

        $message = $exception instanceof ConnectException
            ? 'RoseWatch could not connect to Rose sign-in. Please try again shortly.'
            : KeycloakAuthenticationService::LOGIN_ERROR;

        return redirect('/login')->withErrors(['sso' => $message]);
    }
}
