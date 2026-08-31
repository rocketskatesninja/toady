<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Field weather for an op: hourly forecast from the US National Weather Service
 * (api.weather.gov — free, keyless) plus locally-computed sun/golden-hour times.
 * Centred on the op's waypoints. US-only (NWS coverage).
 */
class WeatherController extends Controller
{
    use AuthorizesOpAccess;

    public function show(Request $request, Op $op): JsonResponse
    {
        $this->requireParticipant($op, $request->user());

        $center = $this->center($op);
        if (! $center) {
            return response()->json(['ok' => false, 'error' => 'Add a placed waypoint to locate weather.']);
        }

        [$lat, $lng] = $center;
        $key = "wx:{$lat},{$lng}";

        // Only a GOOD forecast is cached — a transient NWS failure must never be pinned for 15 minutes,
        // or the panel stays broken until the cache expires. On a miss/failure we re-fetch each request.
        $weather = Cache::get($key);
        if (! is_array($weather) || ! ($weather['ok'] ?? false)) {
            $weather = $this->forecast($lat, $lng);
            if ($weather['ok'] ?? false) {
                Cache::put($key, $weather, now()->addMinutes(15));
            }
        }

        return response()->json(array_merge(
            ['sun' => $this->sun($lat, $lng)],
            $weather, // carries its own real ok/error
        ));
    }

    /** Centroid of the op's PLACED waypoints (NWS rejects >4 decimals). Null when nothing is placed yet. */
    private function center(Op $op): ?array
    {
        $pts = $op->waypoints()->whereNotNull('lat')->whereNotNull('lng')->get(['lat', 'lng']);
        if ($pts->isEmpty()) {
            return null;
        }

        return [round((float) $pts->avg('lat'), 4), round((float) $pts->avg('lng'), 4)];
    }

    private function sun(float $lat, float $lng): array
    {
        $info = date_sun_info(time(), $lat, $lng);
        $iso = fn ($ts) => is_int($ts) ? date('c', $ts) : null;
        $now = time();

        return [
            'dawn' => $iso($info['civil_twilight_begin']),
            'sunrise' => $iso($info['sunrise']),
            'sunset' => $iso($info['sunset']),
            'dusk' => $iso($info['civil_twilight_end']),
            'golden_am_end' => $iso(is_int($info['sunrise']) ? $info['sunrise'] + 3600 : false),
            'golden_pm_start' => $iso(is_int($info['sunset']) ? $info['sunset'] - 3600 : false),
            'is_day' => is_int($info['sunrise']) && is_int($info['sunset'])
                ? ($now >= $info['sunrise'] && $now < $info['sunset'])
                : null,
        ];
    }

    private function forecast(float $lat, float $lng): array
    {
        $headers = ['User-Agent' => 'toady.net Ingress ops (admin@toady.net)', 'Accept' => 'application/geo+json'];
        // NWS (api.weather.gov) is genuinely flaky — its two-step lookup often 500s and succeeds on retry.
        $get = fn (string $url) => Http::withHeaders($headers)->timeout(8)->retry(3, 250, throw: false)->get($url);
        $fail = fn (string $msg) => ['ok' => false, 'place' => null, 'hourly' => [], 'daily' => [], 'error' => $msg];

        try {
            $points = $get("https://api.weather.gov/points/{$lat},{$lng}");
            if (! $points->ok()) {
                // 404 = genuinely outside NWS coverage; anything else is a transient upstream hiccup worth retrying
                return $fail($points->status() === 404 ? 'Weather is US-only (NWS coverage).' : 'Weather service busy — retrying…');
            }

            $props = $points->json('properties');
            $loc = $props['relativeLocation']['properties'] ?? null;
            $place = $loc ? "{$loc['city']}, {$loc['state']}" : null;

            $hourly = [];
            if ($hourlyUrl = $props['forecastHourly'] ?? null) {
                $h = $get($hourlyUrl);
                if ($h->ok()) {
                    $hourly = array_map(fn ($p) => [
                        'time' => $p['startTime'],
                        'temp' => $p['temperature'],
                        'unit' => $p['temperatureUnit'],
                        'short' => $p['shortForecast'],
                        'wind' => $p['windSpeed'],
                        'wind_dir' => $p['windDirection'],
                        'precip' => $p['probabilityOfPrecipitation']['value'] ?? null,
                        'is_day' => $p['isDaytime'],
                    ], array_slice($h->json('properties.periods') ?? [], 0, 12));
                }
            }
            // no hourly data = a failed/partial fetch; report it as not-ok so it isn't cached and the client retries
            if (empty($hourly)) {
                return $fail('Weather service busy — retrying…');
            }

            $daily = [];
            if ($dailyUrl = $props['forecast'] ?? null) {
                $d = $get($dailyUrl);
                if ($d->ok()) {
                    $daily = $this->dailyDays($d->json('properties.periods') ?? []);
                }
            }

            return ['ok' => true, 'place' => $place, 'hourly' => $hourly, 'daily' => $daily];
        } catch (\Throwable $e) {
            return $fail('Weather service unavailable.');
        }
    }

    /** Pair NWS day/night periods into ~7 days with a hi + lo. */
    private function dailyDays(array $periods): array
    {
        $days = [];
        foreach ($periods as $p) {
            $date = substr((string) ($p['startTime'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }
            $days[$date] ??= [
                'date' => $date, 'name' => date('D', strtotime($p['startTime'])),
                'short' => null, 'hi' => null, 'lo' => null,
                'unit' => $p['temperatureUnit'] ?? 'F', 'precip' => null,
            ];
            if ($p['isDaytime'] ?? false) {
                $days[$date]['hi'] = $p['temperature'] ?? null;
                $days[$date]['short'] = $p['shortForecast'] ?? null;
                $days[$date]['precip'] = $p['probabilityOfPrecipitation']['value'] ?? null;
            } else {
                $days[$date]['lo'] = $p['temperature'] ?? null;
                $days[$date]['short'] ??= $p['shortForecast'] ?? null;
            }
        }

        return array_slice(array_values($days), 0, 7);
    }
}
