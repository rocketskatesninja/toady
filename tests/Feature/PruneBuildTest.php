<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * toady:prune-build clears stale hashed assets left behind by past deploys. The contract that matters:
 * never delete what the current manifest serves, and give pre-deploy browsers a grace period to fetch
 * their lazy-loaded chunks before their assets disappear.
 */
class PruneBuildTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/prune-build-'.uniqid();
        mkdir($this->dir.'/assets', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir.'/assets/*') ?: [] as $f) {
                @unlink($f);
            }
            @unlink($this->dir.'/manifest.json');
            @rmdir($this->dir.'/assets');
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    /** Write a build file and age it by $daysOld. */
    private function asset(string $name, int $daysOld = 0): string
    {
        $path = $this->dir.'/assets/'.$name;
        file_put_contents($path, 'x');
        touch($path, now()->subDays($daysOld)->getTimestamp());

        return $path;
    }

    private function manifest(array $map): void
    {
        file_put_contents($this->dir.'/manifest.json', json_encode($map));
    }

    public function test_keeps_live_assets_however_old_and_removes_stale_ones(): void
    {
        $live = $this->asset('app-LIVE.js', 400);   // referenced but ancient
        $liveCss = $this->asset('app-LIVE.css', 400);
        $stale = $this->asset('app-OLD.js', 30);    // unreferenced + past the grace period
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app-LIVE.js', 'css' => ['assets/app-LIVE.css']]]);

        $this->artisan('toady:prune-build', ['--path' => $this->dir, '--days' => 7])->assertSuccessful();

        $this->assertFileExists($live);      // age is irrelevant — the current build serves it
        $this->assertFileExists($liveCss);   // css referenced by the entry is live too
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($this->dir.'/manifest.json'); // never prune the manifest itself
    }

    public function test_unreferenced_assets_inside_the_grace_period_survive(): void
    {
        // a browser that loaded the page just before the last deploy still needs this chunk
        $recent = $this->asset('app-JUSTREPLACED.js', 2);
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app-NEW.js']]);

        $this->artisan('toady:prune-build', ['--path' => $this->dir, '--days' => 7])->assertSuccessful();

        $this->assertFileExists($recent);
    }

    public function test_dry_run_reports_but_deletes_nothing(): void
    {
        $stale = $this->asset('app-OLD.js', 30);
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app-NEW.js']]);

        $this->artisan('toady:prune-build', ['--path' => $this->dir, '--days' => 7, '--dry-run' => true])
            ->expectsOutputToContain('would remove')
            ->assertSuccessful();

        $this->assertFileExists($stale);
    }

    public function test_refuses_to_prune_without_a_readable_manifest(): void
    {
        $orphan = $this->asset('app-OLD.js', 30);
        // no manifest written at all → we can't tell live from stale

        $this->artisan('toady:prune-build', ['--path' => $this->dir, '--days' => 7])->assertFailed();
        $this->assertFileExists($orphan, 'a missing manifest must never trigger a blind delete');

        // ...and a corrupt one is treated the same way
        file_put_contents($this->dir.'/manifest.json', '{not json');
        $this->artisan('toady:prune-build', ['--path' => $this->dir, '--days' => 7])->assertFailed();
        $this->assertFileExists($orphan);
    }
}
