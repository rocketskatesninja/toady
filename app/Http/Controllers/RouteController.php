<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Walking route through an op's waypoints. Proxies OpenRouteService (foot-walking) so the
 * API key stays server-side; falls back to keyless OSRM driving, then to a straight line
 * (the frontend draws that itself when geometry is null). US/global per ORS coverage.
 *
 * Returns the road-following geometry plus the route's total distance (metres) and an
 * on-foot ETA (seconds). ETA is always walking-paced: ORS foot durations are used directly;
 * OSRM's driving duration is discarded and re-derived from distance so the number an agent
 * sees is consistently "how long to walk this", never "how long to drive it".
 */
class RouteController extends Controller
{
    use AuthorizesOpAccess;

    private const WALK_MPS = 1.35; // ~4.9 km/h — a purposeful walking pace for the on-foot ETA

    public function show(Request $request, Op $op): JsonResponse
    {
        $this->requireParticipant($op, $request->user());

        // Coordinates are tightly bounded to real lat/lng so they can only address the routing
        // services' coordinate endpoints — never an attacker-chosen host (no SSRF surface).
        $data = $request->validate([
            'coordinates' => ['required', 'array', 'min:2', 'max:25'],
            'coordinates.*' => ['array', 'size:2'],
            'coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
        ]);

        $coords = $data['coordinates'];
        // v2 key: the cached shape changed from a bare geometry to {geometry,distance,duration,mode}.
        $route = Cache::remember('route:v2:'.md5(json_encode($coords)), now()->addMinutes(30), fn () => $this->route($coords));

        return response()->json($route);
    }

    /** @return array{geometry:?array, distance:float, duration:float, mode:string} */
    private function route(array $coords): array
    {
        // straight-line distance as the floor: the frontend draws this line itself when geometry is null,
        // and it backstops a service that returns geometry but no summary.
        $straight = $this->straightMeters($coords);

        if ($key = config('services.ors.key')) {
            try {
                $r = Http::withHeaders(['Authorization' => $key, 'Content-Type' => 'application/json'])
                    ->timeout(10)
                    ->post('https://api.openrouteservice.org/v2/directions/foot-walking/geojson', ['coordinates' => $coords]);
                if ($r->ok() && ($f = $r->json('features.0')) && ($g = $f['geometry'] ?? null)) {
                    $dist = $f['properties']['summary']['distance'] ?? $straight;

                    return ['geometry' => $g, 'distance' => $dist, 'duration' => $f['properties']['summary']['duration'] ?? $dist / self::WALK_MPS, 'mode' => 'foot'];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // keyless fallback so routing still follows roads before an ORS key is configured. Its geometry
        // is a road path (good), but its duration is a driving estimate — re-derive an on-foot ETA.
        try {
            $path = implode(';', array_map(fn ($c) => "{$c[0]},{$c[1]}", $coords));
            $r = Http::timeout(10)->get("https://router.project-osrm.org/route/v1/driving/{$path}?overview=full&geometries=geojson");
            if ($r->ok() && $r->json('code') === 'Ok') {
                $dist = $r->json('routes.0.distance') ?? $straight;

                return ['geometry' => $r->json('routes.0.geometry'), 'distance' => $dist, 'duration' => $dist / self::WALK_MPS, 'mode' => 'road'];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['geometry' => null, 'distance' => $straight, 'duration' => $straight / self::WALK_MPS, 'mode' => 'direct'];
    }

    /** Great-circle length of the polyline through [lng,lat] pairs, in metres. */
    private function straightMeters(array $coords): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($coords); $i++) {
            [$lng1, $lat1] = $coords[$i - 1];
            [$lng2, $lat2] = $coords[$i];
            $dLat = deg2rad($lat2 - $lat1);
            $dLng = deg2rad($lng2 - $lng1);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
            $total += 6371000 * 2 * asin(min(1.0, sqrt($a)));
        }

        return $total;
    }
}
