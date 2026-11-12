<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('toady:prune-build {--days=7 : Keep unreferenced files this many days before removing them} {--path= : Build directory to prune (defaults to public/build)} {--dry-run : Report what would be removed without deleting}')]
#[Description('Remove stale hashed assets left in public/build by past deploys. Anything the CURRENT manifest serves is kept no matter how old; everything else is kept for a grace period first, so browsers still holding pre-deploy HTML can fetch their lazy-loaded chunks. Refuses to run without a readable manifest.')]
class PruneBuild extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = max(0, (int) $this->option('days'));
        $dir = rtrim($this->option('path') ?: public_path('build'), '/');
        $manifest = $dir.'/manifest.json';

        // Without a manifest we can't tell live assets from stale ones — deleting blind could take out the
        // running build, so bail rather than guess.
        if (! is_file($manifest) || ! is_array($json = json_decode((string) file_get_contents($manifest), true))) {
            $this->error("No readable build manifest at {$manifest} — refusing to prune.");

            return self::FAILURE;
        }

        // Everything the current build serves: entry chunks, their css, and any assets they pull in.
        $live = ['manifest.json' => true];
        foreach ($json as $entry) {
            foreach (['file', 'css', 'assets'] as $key) {
                foreach ((array) ($entry[$key] ?? []) as $f) {
                    $live[ltrim((string) $f, '/')] = true;
                }
            }
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        [$removed, $bytes, $keptYoung] = [0, 0, 0];

        foreach ($this->files($dir) as $path) {
            $rel = ltrim(str_replace($dir, '', $path), '/');
            if (isset($live[$rel])) {
                continue; // referenced by the current build — keep regardless of age
            }
            if (filemtime($path) > $cutoff) {
                $keptYoung++; // unreferenced but still inside the grace window

                continue;
            }
            $size = filesize($path) ?: 0;
            if (! $dry && ! @unlink($path)) {
                $this->warn("could not remove {$rel}");

                continue;
            }
            $removed++;
            $bytes += $size;
            $this->line(($dry ? 'would remove  ' : 'removed  ').$rel);
        }

        $mb = number_format($bytes / 1048576, 1);
        $this->info(($dry ? "Would remove {$removed} stale file(s), freeing {$mb} MB" : "Removed {$removed} stale file(s), freed {$mb} MB")
            .'; kept '.count($live).' live + '.$keptYoung." within the {$days}-day grace period.");

        return self::SUCCESS;
    }

    /** @return list<string> every file under $dir, recursively */
    private function files(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
