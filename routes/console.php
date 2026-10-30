<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
