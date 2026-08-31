<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class MapTileController extends Controller
{
    /**
     * Proxy a TomTom traffic-flow tile so the API key stays server-side.
     * Tiles are cached briefly (traffic refreshes ~every 1–2 min) to stay well under the free tier.
     */
    public function traffic(Request $request, int $z, int $x, int $y): Response
    {
        $key = config('services.tomtom.key');
        abort_unless($key, 404);
        abort_unless($z >= 0 && $z <= 22 && $x >= 0 && $y >= 0, 404);

        // TomTom traffic-flow tiles only exist for z2–22; below that it returns a 400 "zoom level not
        // supported" error. For those (and any upstream hiccup) serve a transparent tile so the map
        // shows nothing rather than surfacing the provider's error.
        $png = $z >= 2 ? Cache::remember("traffic:$z:$x:$y", 90, function () use ($z, $x, $y, $key) {
            $res = Http::timeout(6)->get("https://api.tomtom.com/traffic/map/4/tile/flow/relative0/$z/$x/$y.png", [
                'key' => $key,
                'tileSize' => 256,
            ]);

            return $res->successful() ? $res->body() : null;
        }) : null;

        $png ??= base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

        return response($png, 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=90');
    }
}
