<?php

namespace App\Console\Commands;

use App\Models\MasterPortal;
use App\Support\TravelTools;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('toady:catalog-regions {--limit=300 : Most lookups in one run (one a second)}')]
#[Description('Label catalog portals that have no region with their county (OpenStreetMap). Regions already set are never changed.')]
class LabelCatalogRegions extends Command
{
    /** Portals are looked up one small square at a time (0.02° ≈ 2 km), not one by one: a square shares a county. */
    private const CELL = 0.02;

    public function handle(): int
    {
        $cell = 'CAST(ROUND(lat / '.self::CELL.') AS INTEGER) AS a, CAST(ROUND(lng / '.self::CELL.') AS INTEGER) AS b';
        $squares = MasterPortal::whereNull('region')->selectRaw("{$cell}, MIN(id) AS first_id, COUNT(*) AS n")
            ->groupBy('a', 'b')->orderByDesc('n')->get()
            // a square OpenStreetMap had no answer for is left alone for a month, not asked again every night
            ->reject(fn ($s) => Cache::has("catalog:region:miss:{$s->a}:{$s->b}"))
            ->take(max(1, (int) $this->option('limit')));

        $labelled = 0;
        foreach ($squares as $s) {
            $at = MasterPortal::find($s->first_id); // a real portal's position: always on land, unlike the square's middle
            $region = $at ? TravelTools::countyFor($at->lat, $at->lng) : null;
            if ($region === null) {
                Cache::put("catalog:region:miss:{$s->a}:{$s->b}", 1, now()->addDays(30));

                continue;
            }
            $labelled += MasterPortal::whereNull('region')
                ->whereRaw('CAST(ROUND(lat / '.self::CELL.') AS INTEGER) = ? AND CAST(ROUND(lng / '.self::CELL.') AS INTEGER) = ?', [$s->a, $s->b])
                ->update(['region' => $region]);
        }
        $folded = $this->fold();
        $left = MasterPortal::whereNull('region')->count();
        $this->info("Labelled {$labelled} portals in {$squares->count()} lookups".($folded ? ", folded {$folded} into the owner's regions" : '')."; {$left} still have no region.");

        return self::SUCCESS;
    }

    /**
     * Where the owner has split a county into finer regions of their own (services.toady.catalog_region_folds), a
     * portal labelled with that county takes the region of its nearest neighbour among them.
     */
    private function fold(): int
    {
        $folded = 0;
        foreach ((array) config('services.toady.catalog_region_folds') as $county => $regions) {
            $regions = array_values(array_filter((array) $regions, 'is_string'));
            if (! is_string($county) || ! $regions) {
                continue;
            }
            foreach (MasterPortal::where('region', $county)->get() as $portal) {
                $near = MasterPortal::whereIn('region', $regions)->closestTo($portal->lat, $portal->lng)->value('region');
                if ($near !== null) {
                    $portal->update(['region' => $near]);
                    $folded++;
                }
            }
        }

        return $folded;
    }
}
