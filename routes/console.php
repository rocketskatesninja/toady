<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Hack-timer alerts: always watching. Each run watches ~62 s (waking every second), so with one started each minute
// they overlap and there's never a gap — safe because each alert is claimed exactly once. No withoutOverlapping: its
// 24 h lock could silence alerts for a day after a crash. Listed first and in the background, so the queue drain
// (up to 55 s) never delays it.
Schedule::command('toady:hack-alerts')->everyMinute()->runInBackground();

// Drain the queue (broadcast emails are dispatched with staggered delays) — runs ~once a minute,
// processes whatever is due, then exits. Serial, so the SMTP relay only ever gets one send at a time.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')->everyMinute()->withoutOverlapping();

// Nightly housekeeping so nothing accumulates unbounded (all idempotent, safe to miss a night):
//   - stale hashed assets stranded in public/build by additive deploys
//   - failed jobs older than a week (e.g. from an SMTP outage)
//   - per-model retention: notifications >60d, audit logs >1y (see each model's prunable())
Schedule::command('toady:prune-build')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();
// newly imported catalog portals get their county as a region (one OpenStreetMap lookup a second, so it runs apart)
Schedule::command('toady:catalog-regions')->dailyAt('04:10')->runInBackground()->withoutOverlapping();
// the database cache only drops an expired row when that same key is read again — and map tiles, routes and
// lookups are mostly never re-read, so sweep them
Schedule::call(fn () => DB::table('cache')->where('expiration', '<', time())->delete())->daily()->name('prune-expired-cache');
