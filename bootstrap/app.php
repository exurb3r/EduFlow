<?php

use App\Http\Middleware\EnforceSchoolRegistration;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleColorTheme;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
$middleware->encryptCookies(except: ['appearance', 'color-theme', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: ['api/webhooks/*']);

        $middleware->web(append: [
            EnforceSchoolRegistration::class,
            HandleAppearance::class,
            HandleColorTheme::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['mfa_code']);
    })->create();
