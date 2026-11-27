<?php

namespace App\Console\Commands;

use App\Models\MasterPortal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PDO;

#[Signature('toady:catalog-seed {path : Path to the source portals.db}')]
#[Description('Seed/refresh the owner master catalog from the legacy portals.db (read-only). Idempotent; preserves hand-entered field intel.')]
class SeedCatalog extends Command
{
    public function handle(): int
    {
        $path = $this->argument('path');

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

        // each row keeps its own source label; MasterPortal::import holds the rules (what an import may overwrite)
        $added = 0;
        foreach (collect($rows)->groupBy('source') as $source => $group) {
            $added += MasterPortal::import($group->all(), (string) $source)['added'];
        }
        $this->info('Master catalog now holds '.MasterPortal::count()." portals (+{$added} new).");

        return self::SUCCESS;
    }
}
