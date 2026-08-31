<?php

namespace App\Support;

/**
 * Normalize an IITC export into portals + links.
 *
 * Accepts either:
 *  - Draw Tools export  — a JSON array of {type:'polyline'|'polygon'|'marker', latLngs|latLng, ...}.
 *    Polyline/polygon edges become links; every vertex/marker becomes a portal.
 *  - Bookmarks export   — {portals:{folder:{bkmrk:{id:{guid,latlng,label}}}}}. Portals only, no links.
 *  - Planner export     — {layerdata:[…drawtools…], titledata:{"lat,lng":{title,guid}}}. layerdata is
 *    parsed as geometry; titledata names each portal (and supplies its guid) by coordinate.
 *
 * Output: ['portals' => [['lat','lng','guid'?,'label'?], ...], 'links' => [[fromIdx, toIdx], ...]].
 * Vertices are de-duplicated by coordinate (same portal across many edges → one entry); links are
 * de-duplicated undirected (one physical link regardless of who throws it).
 */
class FieldPlanImporter
{
    public const MAX_PORTALS = 300;
    public const MAX_LINKS = 800;

    public static function parse(array $json): array
    {
        $portals = [];
        $index = [];
        $links = [];
        $linkSeen = [];

        // Some planner exports wrap the geometry as {layerdata:[…], titledata:{"lat,lng":{title,guid}}}.
        // Index those titles by normalized coordinate so each vertex inherits the real portal name + guid.
        $titles = [];
        foreach (($json['titledata'] ?? []) as $coord => $info) {
            if (! is_array($info) || ! is_string($coord) || ! str_contains($coord, ',')) {
                continue;
            }
            [$tlat, $tlng] = array_map('floatval', explode(',', $coord, 2));
            $titles[round($tlat, 6).','.round($tlng, 6)] = ['title' => $info['title'] ?? null, 'guid' => $info['guid'] ?? null];
        }

        $addPortal = function (float $lat, float $lng, ?string $guid = null, ?string $label = null) use (&$portals, &$index, $titles): int {
            $key = round($lat, 6).','.round($lng, 6);
            if (isset($titles[$key])) {
                $guid = $guid ?: $titles[$key]['guid'];
                $label = $label ?: $titles[$key]['title'];
            }
            if (! array_key_exists($key, $index)) {
                if (count($portals) >= self::MAX_PORTALS) {
                    return -1; // bounded — drop excess vertices instead of growing unbounded
                }
                $index[$key] = count($portals);
                $portals[] = ['lat' => $lat, 'lng' => $lng, 'guid' => $guid, 'label' => $label];

                return $index[$key];
            }
            $i = $index[$key]; // backfill identity if a later occurrence carries it
            if ($guid && empty($portals[$i]['guid'])) {
                $portals[$i]['guid'] = $guid;
            }
            if ($label && empty($portals[$i]['label'])) {
                $portals[$i]['label'] = $label;
            }

            return $i;
        };

        $addLink = function (int $a, int $b) use (&$links, &$linkSeen) {
            if ($a < 0 || $b < 0 || $a === $b || count($links) >= self::MAX_LINKS) {
                return; // skip capped-out vertices and stop once the link cap is hit
            }
            $k = min($a, $b).'-'.max($a, $b);
            if (isset($linkSeen[$k])) {
                return;
            }
            $linkSeen[$k] = true;
            $links[] = [$a, $b];
        };

        // ---- Bookmarks export (portals only) ----
        if (isset($json['portals']) && is_array($json['portals'])) {
            foreach ($json['portals'] as $folder) {
                if (! is_array($folder)) {
                    continue;
                }
                foreach (($folder['bkmrk'] ?? []) as $b) {
                    if (! is_array($b) || ! is_string($b['latlng'] ?? null) || ! str_contains($b['latlng'], ',')) {
                        continue;
                    }
                    [$lat, $lng] = array_map('floatval', explode(',', $b['latlng'], 2));
                    $addPortal($lat, $lng, $b['guid'] ?? null, $b['label'] ?? null);
                }
            }

            return self::cap($portals, $links);
        }

        // ---- Draw Tools export (geometry) — a bare array, or the `layerdata` of a wrapped planner export ----
        $items = (isset($json['layerdata']) && is_array($json['layerdata'])) ? $json['layerdata'] : $json;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? null;

            if ($type === 'marker' && isset($item['latLng']['lat'], $item['latLng']['lng'])) {
                $addPortal((float) $item['latLng']['lat'], (float) $item['latLng']['lng']);
            } elseif (($type === 'polyline' || $type === 'polygon') && ! empty($item['latLngs']) && is_array($item['latLngs'])) {
                $idxs = [];
                foreach ($item['latLngs'] as $ll) {
                    if (isset($ll['lat'], $ll['lng'])) {
                        $idxs[] = $addPortal((float) $ll['lat'], (float) $ll['lng']);
                    }
                }
                for ($i = 0; $i + 1 < count($idxs); $i++) {
                    $addLink($idxs[$i], $idxs[$i + 1]);
                }
                if ($type === 'polygon' && count($idxs) > 2) {
                    $addLink($idxs[count($idxs) - 1], $idxs[0]); // close the triangle
                }
            }
            // circle / other shapes are ignored
        }

        return self::cap($portals, $links);
    }

    private static function cap(array $portals, array $links): array
    {
        return [
            'portals' => array_slice($portals, 0, self::MAX_PORTALS),
            'links' => array_values(array_filter(
                array_slice($links, 0, self::MAX_LINKS),
                fn ($l) => $l[0] < self::MAX_PORTALS && $l[1] < self::MAX_PORTALS,
            )),
        ];
    }
}
