<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (QueryException $error, Request $request) {
            if ($request->isMethod('get') || ! in_array((string) $error->getCode(), ['23000', '23505'])) {
                return null;
            }

            return back()->withErrors(['record' => 'This change conflicts with an existing record. Reload and review the selected IDs, IP addresses, or recorder channels.']);
        });
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 413) {
                return back()->withErrors(['upload' => 'The upload is too large. Use up to six images of 8 MB each.']);
            }

            return $response;
        });
    })->create();
