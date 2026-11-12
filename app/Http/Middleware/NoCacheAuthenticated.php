<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated pages must not be cached — otherwise the browser's back/forward cache
 * restores a logged-in page after logout, whose polling/XHR then 401s. `no-store` also
 * disables bfcache, so pressing Back re-requests and lands on the sign-in page.
 */
class NoCacheAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
