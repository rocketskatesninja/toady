<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy. Scripts are locked to same-origin + a per-request nonce (the only inline
 * script is the theme no-flash snippet, which carries the nonce) — so an injected <script> can't run.
 * Styles allow 'unsafe-inline' because Vue :style bindings and MapLibre both set inline style attrs.
 * The allowlisted hosts are the map basemap / satellite / radar tile origins (TomTom + weather are
 * proxied through this origin, so they need no entry).
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        // generate the nonce BEFORE the view renders so @vite tags + the inline theme script carry it
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $tiles = 'https://tiles.openfreemap.org https://server.arcgisonline.com https://tilecache.rainviewer.com';
        // Niantic/Ingress portal photos are hosted on Google user-content CDNs (lh3.googleusercontent.com etc.)
        $portalImg = ' https://*.googleusercontent.com https://*.ggpht.com';
        // Google Analytics origins — only allowlisted when GA is actually configured (keeps CSP tight otherwise)
        $ga = config('services.google_analytics.id')
            ? ' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com'
            : '';
        $gaScript = $ga ? ' https://www.googletagmanager.com' : '';
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$gaScript}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: {$tiles}{$portalImg}{$ga}",
            "connect-src 'self' https://api.rainviewer.com {$tiles}{$ga}",
            "font-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
