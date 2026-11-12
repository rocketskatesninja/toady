<?php

namespace App\Console\Commands;

use App\Models\MasterPortal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PDO;

#[Signature('toady:catalog-seed {--path= : Path to the source portals.db (defaults to CATALOG_SQLITE_PATH)}')]
#[Description('Seed/refresh the owner master catalog from the legacy portals.db (read-only). Idempotent; preserves hand-entered field intel.')]
class SeedCatalog extends Command
{
    public function handle(): int
    {
        $path = $this->option('path') ?: env('CATALOG_SQLITE_PATH', '/home/nope/ingress/portals.db');

        if (! is_file($path)) {
            $this->error("Source catalog not found: {$path}");

            return self::FAILURE;
        }

        $pdo = new PDO('sqlite:file:'.$path.'?mode=ro&immutable=1');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // the portal photo column only exists in newer portals.db files — select it only if present
        $hasImage = collect($pdo->query('PRAGMA table_info(portals)')->fetchAll(PDO::FETCH_ASSOC))
            ->contains(fn ($c) => $c['name'] === 'image');
        $cols = 'guid, title, lat, lng, region, source, first_seen, last_seen'.($hasImage ? ', image' : '');
        $rows = $pdo->query("SELECT {$cols} FROM portals")->fetchAll(PDO::FETCH_ASSOC);

        $total = count($rows);
        $this->info("Read {$total} portals from {$path}");

        $now = now();
        $before = MasterPortal::count();

        foreach (array_chunk($rows, 500) as $chunk) {
            $values = array_map(fn ($r) => [
                'guid' => $r['guid'],
                'title' => $r['title'],
                'lat' => $r['lat'],
                'lng' => $r['lng'],
                'region' => $r['region'],
                'source' => $r['source'],
                'first_seen' => $r['first_seen'],
                'last_seen' => $r['last_seen'],
                'image' => $r['image'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            // Upsert by guid; intel columns are NOT in the update list, so re-seeding never clobbers them.
            MasterPortal::upsert(
                $values,
                ['guid'],
                ['title', 'lat', 'lng', 'region', 'source', 'first_seen', 'last_seen', 'image', 'updated_at']
            );
        }

        $after = MasterPortal::count();
        $this->info("Master catalog now holds {$after} portals (+".($after - $before).' new).');

        return self::SUCCESS;
    }
}
