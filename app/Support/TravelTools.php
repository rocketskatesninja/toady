<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Travel-concierge tools the AI can call (function-calling). Every external service is queried server-side
 * through one HTTP client carrying a descriptive User-Agent (a usage-policy requirement for OSM / Wikimedia).
 * All but one are free and keyless, and their results are cached. The exception is Google place search: it is
 * switched off until a key is configured, has a daily cap, and is never cached (Google's terms).
 *
 * A failed lookup THROWS (the client has ->throw()), so a 429/5xx is never cached as "nothing found".
 * run() turns any failure into a plain sentence for the model, with no URLs or keys in it.
 */
class TravelTools
{
    private const SEARX = 'http://127.0.0.1:8888'; // local SearXNG (systemd service, localhost-only)

    private const OVERPASS = 'https://overpass-api.de/api/interpreter';

    private const GOOGLE_MCP = 'https://mapstools.googleapis.com/mcp'; // Maps Grounding Lite ("Maps Experience agent tools")

    private const DIETS = ['vegetarian', 'vegan', 'halal', 'kosher', 'gluten_free'];

    /** Descriptive User-Agent required by OSM / Wikimedia usage policies. The contact address comes from
     *  config (TOADY_CONTACT_EMAIL) so no personal address is baked into the source — it's appended only
     *  when the deployer sets one. */
    private static function ua(): string
    {
        $contact = trim((string) config('services.toady.contact_email'));

        return 'toady/1.0 (+https://toady.net'.($contact !== '' ? '; '.$contact : '').')';
    }

    /**
     * Every tool, in one place: the label shown while it runs, what the model is told, its JSON-Schema params,
     * the handler, and whether it's switched on. A handler returns a string, or ['text' => …, 'sources' => [[
     * 'title' => …, 'url' => …]]] when the UI must show links under the answer (attribution).
     */
    private static function tools(): array
    {
        $latlng = [
            'type' => 'object',
            'properties' => ['lat' => ['type' => 'number'], 'lng' => ['type' => 'number']],
            'required' => ['lat', 'lng'],
        ];

        return [
            'web_search' => [
                'label' => 'the web',
                'description' => 'Search the live web for current information — directions, public transit, hours, closures, ferry schedules, local news, or anything the other tools do not cover.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
                'handler' => fn (array $a) => self::webSearch((string) ($a['query'] ?? '')),
            ],
            'find_nearby' => [
                'label' => 'OpenStreetMap',
                'description' => 'Find parking, footpaths, gates/barriers, and boat ramps near a coordinate (OpenStreetMap). Use to advise how to physically reach a portal.',
                'parameters' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number'], 'lng' => ['type' => 'number'], 'radius_m' => ['type' => 'integer', 'description' => 'search radius in meters (50–2000, default 400)']], 'required' => ['lat', 'lng']],
                'handler' => fn (array $a) => self::findNearby($a),
            ],
            'find_food' => [
                'label' => 'OpenStreetMap restaurants',
                'description' => 'Find places to eat or drink near a coordinate (OpenStreetMap): restaurants, cafes, fast food, pubs/bars, ice cream. Gives distance, cuisine, opening hours, diet options, phone and website — but NO ratings. Hours are community-mapped and can be stale.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'lat' => ['type' => 'number'], 'lng' => ['type' => 'number'],
                    'radius_m' => ['type' => 'integer', 'description' => 'search radius in meters (100–3000, default 800)'],
                    'cuisine' => ['type' => 'string', 'description' => 'optional OSM cuisine, e.g. pizza, mexican, coffee_shop, burger'],
                    'diet' => ['type' => 'string', 'enum' => self::DIETS, 'description' => 'optional: only places tagged as offering this'],
                ], 'required' => ['lat', 'lng']],
                'handler' => fn (array $a) => self::findFood($a),
            ],
            'search_places' => [
                'label' => 'Google Maps',
                'description' => 'Search Google Maps for places near a coordinate — best for "where should we eat" quality questions (ratings, reviews, what a place is known for). Returns a short summary that cites places as [0], [1]…. Limited daily budget: try find_food first for simple "what is nearby" lists.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'what to look for, e.g. "tacos", "breakfast", "coffee open late"'],
                    'lat' => ['type' => 'number'], 'lng' => ['type' => 'number'],
                    'radius_m' => ['type' => 'integer', 'description' => 'bias radius in meters (100–50000, default 1500)'],
                ], 'required' => ['query', 'lat', 'lng']],
                'handler' => fn (array $a) => self::searchPlaces($a),
                'enabled' => self::googleKey() !== '',
            ],
            'geocode' => [
                'label' => 'address lookup',
                'description' => 'Resolve a place name to coordinates + address, or reverse-geocode a lat/lng to an address (OpenStreetMap Nominatim).',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'place name to look up'], 'lat' => ['type' => 'number'], 'lng' => ['type' => 'number']]],
                'handler' => fn (array $a) => self::geocode($a),
            ],
            'tide_forecast' => [
                'label' => 'NOAA tides',
                'description' => 'Tide highs/lows for ~2 days at the NOAA station nearest a coordinate. Use for coastal/marsh/island access timing (US only).',
                'parameters' => $latlng,
                'handler' => fn (array $a) => self::tides(...self::latlng($a)),
            ],
            'travel_guide' => [
                'label' => 'Wikivoyage',
                'description' => "A destination's community travel guide — how to get in / get around (Wikivoyage).",
                'parameters' => ['type' => 'object', 'properties' => ['place' => ['type' => 'string']], 'required' => ['place']],
                'handler' => fn (array $a) => self::guide(trim((string) ($a['place'] ?? ''))),
            ],
            'elevation' => [
                'label' => 'elevation data',
                'description' => 'Ground elevation at a coordinate, to judge terrain / whether it is a hike.',
                'parameters' => $latlng,
                'handler' => fn (array $a) => self::elevation(...self::latlng($a)),
            ],
        ];
    }

    /** The switched-on tools' provider-agnostic schemas (name/description/JSON-Schema params). The AiController
     *  adapts these to OpenAI's `function.parameters` and Anthropic's `input_schema` shapes. */
    public static function schemas(): array
    {
        $out = [];
        foreach (self::tools() as $name => $t) {
            if ($t['enabled'] ?? true) {
                $out[] = ['name' => $name, 'description' => $t['description'], 'parameters' => $t['parameters']];
            }
        }

        return $out;
    }

    /** Human label for the "consulting …" hint while a tool runs. */
    public static function label(string $name): string
    {
        return self::tools()[$name]['label'] ?? $name;
    }

    /**
     * Execute a tool by name. Never throws. Returns ['text' => what the model reads, 'sources' => links the UI
     * shows under the answer]. Failures are logged here and reach the model only as a generic sentence — raw
     * exception text can carry request URLs, and the model's provider is a third party.
     */
    public static function run(string $name, array $a): array
    {
        $tool = self::tools()[$name] ?? null;
        if (! $tool || ! ($tool['enabled'] ?? true)) {
            return ['text' => "Unknown tool: {$name}.", 'sources' => []];
        }
        try {
            $r = ($tool['handler'])($a);
        } catch (\DomainException $e) {
            return ['text' => $e->getMessage(), 'sources' => []]; // bad input from the model — our own wording, safe to show
        } catch (\Throwable $e) {
            Log::warning("AI tool {$name} failed", ['error' => mb_substr($e->getMessage(), 0, 500)]);

            return ['text' => "The {$name} lookup failed — the service is unavailable right now. Tell the user it failed; do not guess.", 'sources' => []];
        }

        return is_array($r)
            ? ['text' => (string) ($r['text'] ?? ''), 'sources' => array_values($r['sources'] ?? [])]
            : ['text' => (string) $r, 'sources' => []];
    }

    /** Best-effort name of the real-world feature nearest a coordinate (OpenStreetMap), for auto-naming a
     *  portal added by a coords-only Intel link. Portals are usually mapped POIs, so this often returns the
     *  exact name (a plain reverse-geocode misses these — it returns a street address). null if nothing close. */
    public static function nameFor(float $lat, float $lng): ?string
    {
        if (app()->runningUnitTests()) {
            return null; // never reach out to Overpass during tests
        }
        $key = 'tt:name:'.round($lat, 5).':'.round($lng, 5);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }
        try {
            // named features within 60m, excluding roads/boundaries/rivers (those are named but aren't portals)
            $ql = "[out:json][timeout:15];nwr[\"name\"][!\"highway\"][!\"boundary\"][!\"waterway\"](around:60,{$lat},{$lng});out tags center 30;";
            $els = self::http()->timeout(10)->asForm()->post(self::OVERPASS, ['data' => $ql])->json('elements', []);
            $best = null;
            $bestD = INF;
            foreach ($els as $e) {
                $nm = $e['tags']['name'] ?? null;
                [$elat, $elng] = self::elementPoint($e);
                if (! $nm || $elat === null) {
                    continue;
                }
                $d = Geo::meters($lat, $lng, $elat, $elng);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $nm;
                }
            }
        } catch (\Throwable $e) {
            return null; // don't cache failures — retry next time
        }
        if ($best !== null) {
            Cache::put($key, $best, 86400);
        }

        return $best;
    }

    private static function http()
    {
        return Http::withHeaders(['User-Agent' => self::ua()])->timeout(20)->throw();
    }

    private static function googleKey(): string
    {
        return trim((string) config('services.google_maps_grounding.key'));
    }

    /** Validated lat/lng from the model's arguments. */
    private static function latlng(array $a): array
    {
        if (! is_numeric($a['lat'] ?? null) || ! is_numeric($a['lng'] ?? null)) {
            throw new \DomainException('Provide lat and lng as numbers.');
        }
        $lat = (float) $a['lat'];
        $lng = (float) $a['lng'];
        if (abs($lat) > 90 || abs($lng) > 180) {
            throw new \DomainException('lat/lng are out of range.');
        }

        return [$lat, $lng];
    }

    /** Run an Overpass query. A `remark` means Overpass gave up (timeout/overload) — treat it as a failure,
     *  not as "nothing here", so it isn't cached. */
    private static function overpass(string $ql): array
    {
        // The public instance often answers 429/502/504 under load and asks clients to back off and retry.
        $json = self::http()->timeout(30)->asForm()
            ->retry(2, 2000, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true))
            ->post(self::OVERPASS, ['data' => $ql])->json();
        if (! is_array($json) || ! empty($json['remark'])) {
            throw new \RuntimeException('Overpass: '.(is_array($json) ? $json['remark'] : 'unreadable response'));
        }

        return $json['elements'] ?? [];
    }

    /** A node's own point, or a way/relation's center. */
    private static function elementPoint(array $e): array
    {
        $lat = $e['lat'] ?? $e['center']['lat'] ?? null;
        $lng = $e['lon'] ?? $e['center']['lon'] ?? null;

        return $lat === null || $lng === null ? [null, null] : [(float) $lat, (float) $lng];
    }

    /** OpenStreetMap's data licence (ODbL) asks for visible credit wherever its data is shown. */
    private static function osmSource(float $lat, float $lng): array
    {
        return ['title' => '© OpenStreetMap contributors', 'url' => 'https://www.openstreetmap.org/#map=17/'.round($lat, 5).'/'.round($lng, 5)];
    }

    /** Space calls to one service at least $ms apart across all PHP workers (Nominatim's policy: ≤1 req/s). */
    private static function paced(string $service, int $ms, callable $call): mixed
    {
        return Cache::lock("tt:pace:{$service}", 30)->block(15, function () use ($service, $ms, $call) {
            $wait = (float) Cache::get("tt:pace:{$service}:at", 0) + $ms / 1000 - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            try {
                return $call();
            } finally {
                Cache::put("tt:pace:{$service}:at", microtime(true), 60);
            }
        });
    }

    private static function webSearch(string $q): string
    {
        if (trim($q) === '') {
            return 'Provide a search query.';
        }
        $results = Cache::remember('tt:web:'.md5(mb_strtolower($q)), 600,
            fn () => self::http()->timeout(25)->get(self::SEARX.'/search', ['q' => $q, 'format' => 'json', 'safesearch' => 0])->json('results', []));
        if (! $results) {
            return "No web results for \"{$q}\".";
        }
        $out = [];
        foreach (array_slice($results, 0, 6) as $r) {
            $out[] = ($r['title'] ?? 'untitled').' — '.($r['url'] ?? '')."\n  ".mb_substr(trim($r['content'] ?? ''), 0, 240);
        }

        return "Web results for \"{$q}\":\n\n".implode("\n\n", $out);
    }

    private static function findNearby(array $a): array|string
    {
        [$lat, $lng] = self::latlng($a);
        $r = max(50, min(2000, (int) ($a['radius_m'] ?? 400)));
        $ql = '[out:json][timeout:25];('.
            "nwr[\"amenity\"=\"parking\"](around:{$r},{$lat},{$lng});".
            "way[\"highway\"~\"^(footway|path)$\"](around:{$r},{$lat},{$lng});".
            "nwr[\"barrier\"~\"^(gate|lift_gate|swing_gate|bollard|entrance|kissing_gate|cycle_barrier|block)$\"](around:{$r},{$lat},{$lng});".
            "nwr[\"leisure\"=\"slipway\"](around:{$r},{$lat},{$lng}););out center 40;";
        $els = Cache::remember('tt:nearby:'.round($lat, 4).':'.round($lng, 4).":{$r}", 3600, fn () => self::overpass($ql));
        if (! $els) {
            return "Nothing mapped (parking, paths, gates, ramps) within {$r}m on OpenStreetMap.";
        }
        $out = [];
        foreach (array_slice($els, 0, 25) as $e) {
            $t = $e['tags'] ?? [];
            $kind = $t['amenity'] ?? $t['leisure'] ?? $t['barrier'] ?? $t['highway'] ?? 'feature';
            [$plat, $plng] = self::elementPoint($e);
            $out[] = trim($kind.' '.($t['name'] ?? '')).($plat !== null ? ' @ '.round($plat, 5).','.round($plng, 5) : '');
        }

        return ['text' => "Nearby (OSM, ≤{$r}m):\n- ".implode("\n- ", array_unique($out)), 'sources' => [self::osmSource($lat, $lng)]];
    }

    private static function findFood(array $a): array|string
    {
        [$lat, $lng] = self::latlng($a);
        $r = max(100, min(3000, (int) ($a['radius_m'] ?? 800)));
        // cuisine goes into the Overpass query text, so it is reduced to OSM's tag alphabet first
        $cuisine = preg_replace('/[^a-z_;]/', '', str_replace([' ', '-'], '_', mb_strtolower(trim((string) ($a['cuisine'] ?? '')))));
        $diet = in_array($a['diet'] ?? null, self::DIETS, true) ? $a['diet'] : '';
        $filter = ($cuisine !== '' ? "[\"cuisine\"~\"{$cuisine}\",i]" : '').($diet !== '' ? "[\"diet:{$diet}\"~\"^(yes|only)$\"]" : '');
        $ql = "[out:json][timeout:25];nwr[\"amenity\"~\"^(restaurant|cafe|fast_food|pub|bar|food_court|ice_cream)$\"]{$filter}(around:{$r},{$lat},{$lng});out tags center 80;";
        $els = Cache::remember('tt:food:'.round($lat, 4).':'.round($lng, 4).":{$r}:{$cuisine}:{$diet}", 3600, fn () => self::overpass($ql));

        $rows = [];
        foreach ($els as $e) {
            $t = $e['tags'] ?? [];
            [$plat, $plng] = self::elementPoint($e);
            if (empty($t['name']) || $plat === null) {
                continue; // unnamed places aren't useful to suggest
            }
            $d = Geo::meters($lat, $lng, $plat, $plng);
            $diets = [];
            foreach (self::DIETS as $k) {
                if (in_array($t["diet:{$k}"] ?? '', ['yes', 'only'], true)) {
                    $diets[] = $k.($t["diet:{$k}"] === 'only' ? ' only' : '');
                }
            }
            $phone = $t['phone'] ?? $t['contact:phone'] ?? null;
            $bits = array_filter([
                $t['amenity'].(isset($t['cuisine']) ? ' ('.str_replace(';', ', ', $t['cuisine']).')' : ''),
                $d < 1000 ? round($d).' m' : round($d / 1000, 1).' km',
                isset($t['opening_hours']) ? 'hours: '.$t['opening_hours'] : null,
                $diets ? 'diet: '.implode(', ', $diets) : null,
                $phone ? "tel {$phone}" : null,
                $t['website'] ?? $t['contact:website'] ?? null,
            ]);
            $rows[] = ['d' => $d, 'line' => $t['name'].' — '.implode(' · ', $bits).' @ '.round($plat, 5).','.round($plng, 5)];
        }
        if (! $rows) {
            return "No matching places to eat mapped within {$r}m on OpenStreetMap (coverage varies — try a wider radius, drop the filter, or use web_search).";
        }
        usort($rows, fn ($x, $y) => $x['d'] <=> $y['d']);

        return [
            'text' => "Places to eat (OSM, ≤{$r}m, nearest first; hours are community-mapped — say to verify):\n- ".implode("\n- ", array_column(array_slice($rows, 0, 20), 'line')),
            'sources' => [self::osmSource($lat, $lng)],
        ];
    }

    /** Google place search via the Maps Grounding Lite MCP endpoint (plain JSON-RPC over HTTP). NOT cached —
     *  Google's terms forbid storing Maps content — and capped per day so a busy op can't run up a bill. */
    private static function searchPlaces(array $a): array|string
    {
        $q = trim((string) ($a['query'] ?? ''));
        if ($q === '') {
            throw new \DomainException('Provide a query, e.g. "tacos" or "coffee".');
        }
        [$lat, $lng] = self::latlng($a);
        $r = max(100, min(50000, (int) ($a['radius_m'] ?? 1500)));
        $cap = max(1, (int) config('services.google_maps_grounding.daily_cap', 200));
        if (RateLimiter::tooManyAttempts('tt:google:day', $cap)) {
            return "Google place search has used today's budget — use find_food (OpenStreetMap) or web_search instead.";
        }
        RateLimiter::hit('tt:google:day', 86400);

        $res = self::http()->timeout(25)
            ->withHeaders(['X-Goog-Api-Key' => self::googleKey(), 'Accept' => 'application/json, text/event-stream'])
            ->post(self::GOOGLE_MCP, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => 'search_places',
                'arguments' => [
                    'text_query' => $q,
                    'location_bias' => ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng], 'radius_meters' => $r]],
                    'page_size' => 5, // >5 costs more
                ],
            ]]);
        $rpc = self::rpcReply($res->body());
        $result = $rpc['result'] ?? null;
        if (! is_array($result) || ! empty($result['isError'])) {
            throw new \RuntimeException('search_places: '.mb_substr(json_encode($rpc['error'] ?? $result['content'] ?? $rpc), 0, 300));
        }
        $data = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '', true);
        if (! is_array($data)) {
            throw new \RuntimeException('search_places: unreadable result');
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        $lines = [];
        $sources = [];
        foreach (array_values($data['places'] ?? []) as $i => $p) {
            $name = $p['attribution']['title'] ?? $p['displayName']['text'] ?? 'place';
            $url = $p['googleMapsLinks']['placeUrl'] ?? $p['attribution']['url'] ?? null;
            $at = isset($p['location']['latitude']) ? ' @ '.round($p['location']['latitude'], 5).','.round($p['location']['longitude'], 5) : '';
            $lines[] = "[{$i}] {$name}{$at}";
            if ($url) {
                $sources[] = ['title' => "{$name} — Google Maps", 'url' => $url];
            }
        }
        if ($summary === '' && ! $lines) {
            return "Google Maps found nothing for \"{$q}\" near there.";
        }

        return [
            'text' => "Google Maps — \"{$q}\":\n{$summary}\n\nPlaces cited above:\n".implode("\n", $lines)."\n(Google Maps links are shown to the user under your answer.)",
            'sources' => $sources,
        ];
    }

    /** The JSON-RPC reply from an MCP endpoint — a plain JSON body, or the last `data:` event of an SSE body. */
    private static function rpcReply(string $body): array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        $reply = [];
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:') && is_array($d = json_decode(trim(substr($line, 5)), true)) && isset($d['jsonrpc'])) {
                $reply = $d;
            }
        }

        return $reply;
    }

    private static function geocode(array $a): string
    {
        $q = trim((string) ($a['query'] ?? ''));
        if ($q !== '') {
            $m = Cache::remember('tt:geo:'.md5(mb_strtolower($q)), 86400,
                fn () => self::paced('nominatim', 1000, fn () => self::http()->get('https://nominatim.openstreetmap.org/search', ['q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1])->json()));
            if (! $m) {
                return "No match for \"{$q}\".";
            }

            return "\"{$q}\" → {$m[0]['display_name']} @ {$m[0]['lat']},{$m[0]['lon']}";
        }
        if (isset($a['lat'], $a['lng'])) {
            [$lat, $lng] = self::latlng($a);
            $m = Cache::remember('tt:rgeo:'.round($lat, 5).':'.round($lng, 5), 86400,
                fn () => self::paced('nominatim', 1000, fn () => self::http()->get('https://nominatim.openstreetmap.org/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2'])->json()));

            return ($m['display_name'] ?? 'No address found')." ({$lat},{$lng})";
        }

        return 'Provide either a place name (query) or lat+lng.';
    }

    private static function tides(float $lat, float $lng): string
    {
        $stations = Cache::remember('tt:noaa:stations', 86400,
            fn () => self::http()->get('https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/stations.json', ['type' => 'tidepredictions'])->json('stations', []));
        if (! $stations) {
            return 'NOAA tide-station list unavailable right now.';
        }
        $near = null;
        $best = INF;
        foreach ($stations as $s) {
            $d = Geo::meters($lat, $lng, (float) $s['lat'], (float) $s['lng']) / 1000; // km
            if ($d < $best) {
                $best = $d;
                $near = $s;
            }
        }
        if (! $near || $best > 200) {
            return 'No NOAA tide station within range (US coastal waters only).';
        }
        $preds = self::http()->get('https://api.tidesandcurrents.noaa.gov/api/prod/datagetter', [
            'product' => 'predictions', 'station' => $near['id'], 'date' => 'today', 'range' => 48,
            'datum' => 'MLLW', 'time_zone' => 'lst_ldt', 'units' => 'english', 'interval' => 'hilo', 'format' => 'json', 'application' => 'toady',
        ])->json('predictions', []);
        if (! $preds) {
            return "Nearest station is {$near['name']} but no predictions came back.";
        }
        $rows = array_map(fn ($p) => $p['t'].'  '.($p['type'] === 'H' ? 'HIGH' : 'low ').'  '.$p['v'].' ft', array_slice($preds, 0, 8));

        return "Tides at {$near['name']} (~".round($best)." km away):\n- ".implode("\n- ", $rows);
    }

    private static function guide(string $place): array|string
    {
        if ($place === '') {
            return 'Provide a place name.';
        }
        $text = Cache::remember('tt:guide:'.md5(mb_strtolower($place)), 86400, function () use ($place) {
            $sections = self::http()->get('https://en.wikivoyage.org/w/api.php', ['action' => 'parse', 'page' => $place, 'prop' => 'sections', 'format' => 'json'])->json('parse.sections', []);
            if (! $sections) {
                return "No Wikivoyage guide found for \"{$place}\".";
            }
            $want = collect($sections)->whereIn('line', ['Get in', 'Get around'])->pluck('index');
            if ($want->isEmpty()) {
                return "Wikivoyage has \"{$place}\" but no Get in/around sections.";
            }
            $out = [];
            foreach ($want as $idx) {
                $wt = self::http()->get('https://en.wikivoyage.org/w/api.php', ['action' => 'parse', 'page' => $place, 'prop' => 'wikitext', 'section' => $idx, 'format' => 'json'])->json('parse.wikitext', []);
                $out[] = self::stripWiki($wt['*'] ?? '');
            }

            return "Wikivoyage — {$place} (CC BY-SA, verify times locally):\n".mb_substr(trim(implode("\n\n", $out)), 0, 2500);
        });

        return str_starts_with($text, 'Wikivoyage —')
            ? ['text' => $text, 'sources' => [['title' => "{$place} — Wikivoyage (CC BY-SA)", 'url' => 'https://en.wikivoyage.org/wiki/'.rawurlencode(str_replace(' ', '_', $place))]]]
            : $text;
    }

    private static function elevation(float $lat, float $lng): string
    {
        $m = Cache::remember('tt:elev:'.round($lat, 4).':'.round($lng, 4), 86400, function () use ($lat, $lng) {
            try {
                $e = self::http()->get('https://api.open-elevation.com/api/v1/lookup', ['locations' => "{$lat},{$lng}"])->json('results.0.elevation');
                if ($e !== null) {
                    return $e;
                }
            } catch (\Throwable $e) {
            }

            return self::http()->get('https://api.opentopodata.org/v1/aster30m', ['locations' => "{$lat},{$lng}"])->json('results.0.elevation');
        });

        return $m === null ? 'Elevation unavailable.' : "Elevation at {$lat},{$lng} ≈ ".round($m).' m ('.round($m * 3.28084).' ft).';
    }

    private static function stripWiki(string $s): string
    {
        $s = preg_replace('/\{\{[^{}]*\}\}/', '', $s);              // templates
        $s = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]*)\]\]/', '$1', $s); // wikilinks → label
        $s = preg_replace('/\[https?:\/\/\S+\s+([^\]]+)\]/', '$1', $s);  // ext links → label
        $s = preg_replace("/'''?/", '', $s);                        // bold/italic
        $s = preg_replace('/=={2,}\s*([^=]+?)\s*=={2,}/', "\n[$1]", $s); // headings
        $s = preg_replace('/<[^>]+>/', '', $s);                     // stray html

        return trim(preg_replace('/\n{3,}/', "\n\n", $s));
    }
}
