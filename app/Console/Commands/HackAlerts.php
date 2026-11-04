<?php

namespace App\Console\Commands;

use App\Models\HackTimer;
use App\Support\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('toady:hack-alerts {--seconds=62 : How long this run watches — the scheduler starts one every minute, so runs overlap and there is never a gap}')]
#[Description('Push "ready" to an agent\'s devices the moment a hack timer\'s cooldown or burnout runs out. Wakes just after every whole second (alert times are whole seconds); overlapping runs are safe because each alert is claimed exactly once.')]
class HackAlerts extends Command
{
    public function handle(): int
    {
        $until = microtime(true) + (int) $this->option('seconds');
        while (true) {
            try {
                $this->sendDue();
            } catch (\Throwable $e) {
                report($e); // a locked database or a push hiccup must not end the watch
            }
            if (microtime(true) >= $until) {
                return self::SUCCESS;
            }
            usleep((int) ((1 - fmod(microtime(true), 1)) * 1_000_000) + 5_000); // just past the next whole second
        }
    }

    private function sendDue(): void
    {
        $now = now();
        $due = HackTimer::with(['user', 'op:id,public_id', 'waypoint:id,title'])->alertDueBy($now)->get();
        foreach ($due as $timer) {
            // claim it first, only if alert_at is unchanged — a hack that just moved it is neither announced nor lost,
            // and an overlapping run can't send it twice. toBase: no updated_at bump (that clock is for the idle prune)
            $claimed = HackTimer::whereKey($timer->id)->where('alert_at', $timer->getRawOriginal('alert_at'))
                ->toBase()->update(['alert_at' => null]);
            $late = $timer->alert_at->diffInSeconds($now);
            if (! $claimed || $late > HackTimer::ALERT_GRACE) {
                continue; // someone else has it — or the watcher was down and an alert this stale is noise, not news
            }
            ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag] = $timer->alertPayload();
            Notifier::send($timer->user, 'hacks', $title, $body, $url, $timer->op_id, $tag, feed: false);
            if ($this->output->isVerbose()) {
                $this->line(sprintf('%s  timer %d due %s → sent %.2f s after due', now()->format('H:i:s.v'), $timer->id, $timer->alert_at->format('H:i:s'), $late));
            }
        }
        if ($due->isNotEmpty()) {
            Notifier::flush(); // everything due this second, in one batch
        }
    }
}
