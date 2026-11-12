<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A verified user without a callsign must finish onboarding before using the app. */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->callsign && ! $request->routeIs('onboard', 'onboard.store', 'logout')) {
            return redirect()->route('onboard');
        }

        return $next($request);
    }
}
