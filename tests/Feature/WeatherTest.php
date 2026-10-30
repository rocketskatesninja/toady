<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Op} operative + planning op (with a placed waypoint unless $placed is false) */
    private function scene(bool $placed = true): array
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Wx', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'WX']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->waypoints()->create($placed
            ? ['seq' => 1, 'role' => 'anchor', 'title' => 'A', 'lat' => 31.15, 'lng' => -81.49]
            : ['seq' => 1, 'role' => 'anchor', 'title' => 'A']); // unplaced (null lat/lng)

        return [$operative, $op];
    }

    private function fakeNwsOk(): void
    {
        Http::fake([
            'api.weather.gov/points/*' => Http::response(['properties' => [
                'relativeLocation' => ['properties' => ['city' => 'Brunswick', 'state' => 'GA']],
                'forecastHourly' => 'https://api.weather.gov/hourly',
                'forecast' => 'https://api.weather.gov/daily',
            ]], 200),
            'api.weather.gov/hourly' => Http::response(['properties' => ['periods' => [
                ['startTime' => '2026-07-02T12:00:00-04:00', 'temperature' => 88, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'windSpeed' => '5 mph', 'windDirection' => 'S', 'probabilityOfPrecipitation' => ['value' => 10], 'isDaytime' => true],
            ]]], 200),
            'api.weather.gov/daily' => Http::response(['properties' => ['periods' => [
                ['startTime' => '2026-07-02T06:00:00-04:00', 'temperature' => 90, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'isDaytime' => true, 'probabilityOfPrecipitation' => ['value' => 10]],
            ]]], 200),
        ]);
    }

    public function test_unplaced_waypoints_report_no_region(): void
    {
        [$operative, $op] = $this->scene(placed: false);

        $this->actingAs($operative)->getJson("/ops/{$op->public_id}/weather")
            ->assertOk()->assertJson(['ok' => false])->assertJsonPath('error', 'Add a placed waypoint to locate weather.');
    }

    public function test_returns_the_forecast_on_success(): void
    {
        [$operative, $op] = $this->scene();
        $this->fakeNwsOk();

        $this->actingAs($operative)->getJson("/ops/{$op->public_id}/weather")
            ->assertOk()
            ->assertJson(['ok' => true, 'place' => 'Brunswick, GA'])
            ->assertJsonPath('hourly.0.temp', 88);
    }

    public function test_a_transient_upstream_failure_is_not_cached(): void
    {
        [$operative, $op] = $this->scene();

        // one stateful fake so the recovery isn't shadowed by leftover stubs: down first, then up
        $down = true;
        Http::fake(function ($request) use (&$down) {
            if ($down) {
                return Http::response('boom', 500);
            }
            $url = $request->url();
            if (str_contains($url, '/points/')) {
                return Http::response(['properties' => [
                    'relativeLocation' => ['properties' => ['city' => 'Brunswick', 'state' => 'GA']],
                    'forecastHourly' => 'https://api.weather.gov/hourly',
                    'forecast' => 'https://api.weather.gov/daily',
                ]], 200);
            }
            if (str_contains($url, '/hourly')) {
                return Http::response(['properties' => ['periods' => [
                    ['startTime' => '2026-07-02T12:00:00-04:00', 'temperature' => 88, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'windSpeed' => '5 mph', 'windDirection' => 'S', 'probabilityOfPrecipitation' => ['value' => 10], 'isDaytime' => true],
                ]]], 200);
            }

            return Http::response(['properties' => ['periods' => []]], 200);
        });

        // NWS is down → not-ok, and (crucially) this result must NOT be cached
        $this->actingAs($operative)->getJson("/ops/{$op->public_id}/weather")
            ->assertOk()->assertJson(['ok' => false]);

        // service recovers → the very next request re-fetches and succeeds (would stay broken if we'd cached the failure)
        $down = false;
        $this->actingAs($operative)->getJson("/ops/{$op->public_id}/weather")
            ->assertOk()->assertJson(['ok' => true])->assertJsonPath('hourly.0.temp', 88);
    }
}
