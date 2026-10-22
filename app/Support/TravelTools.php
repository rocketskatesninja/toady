<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Free, keyless travel-concierge tools the AI can call (function-calling). Every external service is queried
 * server-side through one HTTP client carrying a descriptive User-Agent (a usage-policy requirement for OSM /
 * Wikimedia) and every result is cached. No API keys, no per-user cost.
 */
class TravelTools
{
    /** Descriptive User-Agent required by OSM / Wikimedia usage policies. The contact address comes from
     *  config (TOADY_CONTACT_EMAIL) so no personal address is baked into the source — it's appended only
     *  when the deployer sets one. */
    private static function ua(): string
    {
        $contact = trim((string) config('services.toady.contact_email'));

        return 'toady/1.0 (+https://toady.net'.($contact !== '' ? '; '.$contact : '').')';
    }

    private const SEARX = 'http://127.0.0.1:8888'; // local SearXNG (systemd service, localhost-only)

    /** Provider-agnostic tool schemas (name/description/JSON-Schema params). The AiController adapts these to
     *  OpenAI's `function.parameters` and Anthropic's `input_schema` shapes. */
    public static function schemas(): array
    {
        $latlng = [
            'type' => 'object',
            'properties' => ['lat' => ['type' => 'number'], 'lng' => ['type' => 'number']],
            'required' => ['lat', 'lng'],
        ];

        return [
            ['name' => 'web_search', 'description' => 'Search the live web for current information — directions, public transit, hours, closures, ferry schedules, local news, or anything the other tools do not cover.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
            ['name' => 'find_nearby', 'description' => 'Find parking, footpaths, gates/barriers, and boat ramps near a coordinate (OpenStreetMap). Use to advise how to physically reach a portal.',
                'parameters' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number'], 'lng' => ['type' => 'number'], 'radius_m' => ['type' => 'integer', 'description' => 'search radius in meters (50–2000, default 400)']], 'required' => ['lat', 'lng']]],
            ['name' => 'geocode', 'description' => 'Resolve a place name to coordinates + address, or reverse-geocode a lat/lng to an address (OpenStreetMap Nominatim).',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'place name to look up'], 'lat' => ['type' => 'number'], 'lng' => ['type' => 'number']]]],
            ['name' => 'tide_forecast', 'description' => 'Tide highs/lows for ~2 days at the NOAA station nearest a coordinate. Use for coastal/marsh/island access timing (US only).', 'parameters' => $latlng],
            ['name' => 'travel_guide', 'description' => "A destination's community travel guide — how to get in / get around (Wikivoyage).",
                'parameters' => ['type' => 'object', 'properties' => ['place' => ['type' => 'string']], 'required' => ['place']]],
            ['name' => 'elevation', 'description' => 'Ground elevation at a coordinate, to judge terrain / whether it is a hike.', 'parameters' => $latlng],
        ];
    }

    /** Execute a tool by name; always returns a string for the model to read (errors included, never thrown). */
    public static function run(string $name, array $a): string
    {
        try {
            return match ($name) {
                'web_search' => self::webSearch((string) ($a['query'] ?? '')),
                'find_nearby' => self::findNearby((float) $a['lat'], (float) $a['lng'], (int) ($a['radius_m'] ?? 400)),
                'geocode' => self::geocode($a['query'] ?? null, isset($a['lat']) ? (float) $a['lat'] : null, isset($a['lng']) ? (float) $a['lng'] : null),
                'tide_forecast' => self::tides((float) $a['lat'], (float) $a['lng']),
                'travel_guide' => self::guide((string) ($a['place'] ?? '')),
                'elevation' => self::elevation((float) $a['lat'], (float) $a['lng']),
                default => "Unknown tool: {$name}.",
            };
        } catch (\Throwable $e) {
            return 'Tool error: '.$e->getMessage();
        }
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
            $els = self::http()->timeout(10)->asForm()->post('https://overpass-api.de/api/interpreter', ['data' => $ql])->json('elements', []);
            $best = null;
            $bestD = INF;
            foreach ($els as $e) {
                $nm = $e['tags']['name'] ?? null;
                $elat = $e['lat'] ?? $e['center']['lat'] ?? null;
                $elng = $e['lon'] ?? $e['center']['lon'] ?? null;
                if (! $nm || $elat === null) {
                    continue;
                }
                $d = self::haversine($lat, $lng, (float) $elat, (float) $elng);
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
        return Http::withHeaders(['User-Agent' => self::ua()])->timeout(20);
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

    private static function findNearby(float $lat, float $lng, int $r): string
    {
        $r = max(50, min(2000, $r));
        $ql = "[out:json][timeout:25];(".
            "nwr[\"amenity\"=\"parking\"](around:{$r},{$lat},{$lng});".
            "way[\"highway\"~\"^(footway|path)$\"](around:{$r},{$lat},{$lng});".
            "nwr[\"barrier\"~\"^(gate|lift_gate|swing_gate|bollard|entrance|kissing_gate|cycle_barrier|block)$\"](around:{$r},{$lat},{$lng});".
            "nwr[\"leisure\"=\"slipway\"](around:{$r},{$lat},{$lng}););out center 40;";
        $els = Cache::remember('tt:nearby:'.round($lat, 4).':'.round($lng, 4).":{$r}", 3600,
            fn () => self::http()->asForm()->post('https://overpass-api.de/api/interpreter', ['data' => $ql])->json('elements', []));
        if (! $els) {
            return "Nothing mapped (parking, paths, gates, ramps) within {$r}m on OpenStreetMap.";
        }
        $out = [];
        foreach (array_slice($els, 0, 25) as $e) {
            $t = $e['tags'] ?? [];
            $kind = $t['amenity'] ?? $t['leisure'] ?? $t['barrier'] ?? $t['highway'] ?? 'feature';
            $plat = $e['lat'] ?? $e['center']['lat'] ?? null;
            $plng = $e['lon'] ?? $e['center']['lon'] ?? null;
            $out[] = trim($kind.' '.($t['name'] ?? '')).($plat ? ' @ '.round($plat, 5).','.round($plng, 5) : '');
        }

        return "Nearby (OSM, ≤{$r}m):\n- ".implode("\n- ", array_unique($out));
    }

    private static function geocode(?string $q, ?float $lat, ?float $lng): string
    {
        if ($q) {
            $m = Cache::remember('tt:geo:'.md5(mb_strtolower($q)), 86400,
                fn () => self::http()->get('https://nominatim.openstreetmap.org/search', ['q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1])->json());
            if (! $m) {
                return "No match for \"{$q}\".";
            }

            return "\"{$q}\" → {$m[0]['display_name']} @ {$m[0]['lat']},{$m[0]['lon']}";
        }
        if ($lat !== null && $lng !== null) {
            $m = Cache::remember('tt:rgeo:'.round($lat, 5).':'.round($lng, 5), 86400,
                fn () => self::http()->get('https://nominatim.openstreetmap.org/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2'])->json());

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
            $d = self::haversine($lat, $lng, (float) $s['lat'], (float) $s['lng']);
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

    private static function guide(string $place): string
    {
        if ($place === '') {
            return 'Provide a place name.';
        }

        return Cache::remember('tt:guide:'.md5(mb_strtolower($place)), 86400, function () use ($place) {
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

    private static function haversine(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $dLat = deg2rad($bLat - $aLat);
        $dLng = deg2rad($bLng - $aLng);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}
