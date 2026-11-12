<?php

namespace Tests\Feature;

use App\Support\TravelTools;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class TravelToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function googleReply(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'x']], 'structuredContent' => [
            'summary' => 'Bubba\'s [0] is loved for breakfast.',
            'places' => [['id' => 'abc', 'location' => ['latitude' => 31.1, 'longitude' => -81.4],
                'attribution' => ['title' => "Bubba's", 'url' => 'https://maps.google.com/?cid=1'],
                'googleMapsLinks' => ['placeUrl' => 'https://maps.google.com/?cid=1']]],
        ]]];
    }

    public function test_a_failed_lookup_is_reported_generically_and_not_cached(): void
    {
        Sleep::fake();
        Http::fake(['overpass-api.de/*' => Http::sequence()
            ->push('<html>rate limited</html>', 429)
            ->push('<html>still busy</html>', 502) // the one retry fails too
            ->push(['elements' => [['type' => 'node', 'id' => 1, 'lat' => 31.1, 'lon' => -81.4, 'tags' => ['amenity' => 'parking']]]])]);

        $first = TravelTools::run('find_nearby', ['lat' => 31.1, 'lng' => -81.4]);
        $this->assertStringContainsString('lookup failed', $first['text']);
        $this->assertStringNotContainsString('http', $first['text']); // no URLs leak to the model's provider

        $second = TravelTools::run('find_nearby', ['lat' => 31.1, 'lng' => -81.4]);
        $this->assertStringContainsString('parking', $second['text']); // the failure wasn't cached as "nothing here"
    }

    public function test_overpass_is_retried_once_when_busy(): void
    {
        Sleep::fake();
        Http::fake(['overpass-api.de/*' => Http::sequence()->push('busy', 504)->push(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 31.1005, 'lon' => -81.4, 'tags' => ['amenity' => 'cafe', 'name' => "Bubba's"]],
        ]])]);

        $this->assertStringContainsString("Bubba's", TravelTools::run('find_food', ['lat' => 31.1, 'lng' => -81.4])['text']);
        Http::assertSentCount(2);
    }

    public function test_an_overpass_timeout_remark_counts_as_a_failure(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [], 'remark' => 'runtime error: Query timed out'])]);

        $this->assertStringContainsString('lookup failed', TravelTools::run('find_food', ['lat' => 31.1, 'lng' => -81.4])['text']);
    }

    public function test_bad_coordinates_get_a_clear_message(): void
    {
        $this->assertSame('Provide lat and lng as numbers.', TravelTools::run('find_food', ['lat' => 'here'])['text']);
    }

    public function test_find_food_lists_named_places_nearest_first_with_osm_credit(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 2, 'lat' => 31.11, 'lon' => -81.4, 'tags' => ['amenity' => 'restaurant', 'name' => 'Far Diner', 'opening_hours' => 'Mo-Su 07:00-14:00']],
            ['type' => 'node', 'id' => 3, 'lat' => 31.1001, 'lon' => -81.4, 'tags' => ['amenity' => 'fast_food']], // unnamed → skipped
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 31.1005, 'lon' => -81.4], 'tags' => ['amenity' => 'cafe', 'name' => "Bubba's", 'diet:vegan' => 'yes']],
        ]])]);

        $r = TravelTools::run('find_food', ['lat' => 31.1, 'lng' => -81.4]);

        $this->assertMatchesRegularExpression("/Bubba's — cafe · 56 m · diet: vegan.*\n- Far Diner — restaurant · 1\.1 km · hours: Mo-Su 07:00-14:00/s", $r['text']);
        $this->assertStringNotContainsString('fast_food', $r['text']);
        $this->assertSame('© OpenStreetMap contributors', $r['sources'][0]['title']);
    }

    public function test_find_food_cuisine_cannot_break_out_of_the_overpass_query(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []])]);

        TravelTools::run('find_food', ['lat' => 31.1, 'lng' => -81.4, 'cuisine' => 'Pizza"];out;', 'diet' => 'not-a-diet']);

        Http::assertSent(fn (Request $r) => str_contains($r['data'], '["cuisine"~"pizza;out;",i]') && ! str_contains($r['data'], 'diet:'));
    }

    public function test_google_search_is_off_until_a_key_is_set(): void
    {
        $names = fn () => array_column(TravelTools::schemas(), 'name');

        $this->assertNotContains('search_places', $names());
        $this->assertSame('Unknown tool: search_places.', TravelTools::run('search_places', ['query' => 'x', 'lat' => 1, 'lng' => 1])['text']);

        config(['services.google_maps_grounding.key' => 'g-key']);
        $this->assertContains('search_places', $names());
    }

    public function test_google_search_sends_the_key_in_a_header_never_caches_and_respects_the_daily_cap(): void
    {
        config(['services.google_maps_grounding.key' => 'g-key', 'services.google_maps_grounding.daily_cap' => 2]);
        Http::fake(['mapstools.googleapis.com/*' => Http::response($this->googleReply())]);
        $ask = fn () => TravelTools::run('search_places', ['query' => 'breakfast', 'lat' => 31.1, 'lng' => -81.4]);

        $r = $ask();
        $this->assertStringContainsString("Bubba's [0] is loved for breakfast.", $r['text']);
        $this->assertSame([['title' => "Bubba's — Google Maps", 'url' => 'https://maps.google.com/?cid=1']], $r['sources']);
        Http::assertSent(fn (Request $q) => $q->hasHeader('X-Goog-Api-Key', 'g-key') && ! str_contains($q->url(), 'g-key')
            && $q['params']['arguments']['text_query'] === 'breakfast'
            && $q['params']['arguments']['location_bias']['circle']['center']['latitude'] === 31.1);

        $ask(); // same question again → Google is asked again (no caching)
        Http::assertSentCount(2);

        $this->assertStringContainsString("used today's budget", $ask()['text']); // cap of 2 reached
        Http::assertSentCount(2);
    }

    public function test_google_search_reads_an_sse_reply_too(): void
    {
        config(['services.google_maps_grounding.key' => 'g-key']);
        Http::fake(['mapstools.googleapis.com/*' => Http::response("event: message\ndata: ".json_encode($this->googleReply())."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $this->assertStringContainsString('loved for breakfast', TravelTools::run('search_places', ['query' => 'breakfast', 'lat' => 31.1, 'lng' => -81.4])['text']);
    }
}
