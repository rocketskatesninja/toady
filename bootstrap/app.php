<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\ContentSecurityPolicy::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\NoCacheAuthenticated::class,
            \App\Http\Middleware\EnsureNotSuspended::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A GET op link that 404s — the op was purged, its public_id is unknown, or the viewer isn't on
        // its roster (requireParticipant 404s non-members on purpose) — lands softly on the dashboard with
        // a deliberately ambiguous notice, so the response never reveals which of those is the case.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->user() && $request->isMethod('GET') && $request->is('ops/*')) {
                return redirect()->route('dashboard')->with('error', 'That op isn’t available — it may have ended, or you’re not on its roster.');
            }
        });
    })->create();
