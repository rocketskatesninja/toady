<?php

namespace App\Console\Commands;

use App\Models\MasterPortal;
use App\Models\OpWaypoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('toady:backfill-waypoint-images {--dry-run : Report what would change without writing}')]
#[Description('Fill missing portal photos on placed waypoints from the master catalog (coordinate match). Idempotent — only touches a waypoint whose image is empty and whose catalog match has one. Fixes ops whose portals were IITC-imported before the import copied photos.')]
class BackfillWaypointImages extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $candidates = OpWaypoint::whereNotNull('lat')
            ->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''))
            ->get(['id', 'lat', 'lng', 'image']);

        $this->info($candidates->count().' placed waypoints missing a photo — checking the catalog…');

        $filled = 0;
        foreach ($candidates as $wp) {
            // the import snapshots the catalog's exact coords, so a tight coordinate match re-finds the same portal
            $img = MasterPortal::whereRaw('ABS(lat - ?) < 1e-5 AND ABS(lng - ?) < 1e-5', [$wp->lat, $wp->lng])
                ->whereNotNull('image')->where('image', '!=', '')
                ->value('image');
            if (! $img) {
                continue;
            }
            if (! $dry) {
                $wp->update(['image' => $img]);
            }
            $filled++;
        }

        $this->info(($dry ? 'Would fill' : 'Filled').' '.$filled.' waypoint photo'.($filled === 1 ? '' : 's').' from the catalog.');

        return self::SUCCESS;
    }
}
