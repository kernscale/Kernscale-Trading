<?php

use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\EnsureApprovedMembership;
use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::get('/ready', ReadinessController::class),
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'approved' => EnsureApprovedMembership::class,
            'security.enrolled' => EnsureSecurityEnrollment::class,
        ]);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureApprovedMembership::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $exception, Request $request): ?RedirectResponse {
            if ($exception->getStatusCode() === 409 && $request->header('X-Inertia')) {
                return back(303)->withErrors(['workspace' => $exception->getMessage() ?: 'This item changed. Refresh before editing.']);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
