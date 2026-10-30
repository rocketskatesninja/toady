<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

/**
 * One funnel for every notification: records a feed row for the recipient and (optionally) fires a
 * background Web Push. Callers are responsible for not notifying a user about their own action.
 */
class Notifier
{
    public static function send(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?int $opId = null,
        bool $push = true,
        ?string $tag = null,
    ): void {
        // respect the recipient's per-type notification preference (null/missing = on)
        if ((($user->notify_prefs ?? [])[$type] ?? true) === false) {
            return;
        }

        Notification::create([
            'user_id' => $user->id,
            'op_id' => $opId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

        if ($push) {
            $payload = array_filter([
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'tag' => $tag ?? ($type.'-'.($opId ?? 'x')),
            ], fn ($v) => $v !== null);
            // deliver after the response is flushed, so the triggering request — especially the "op goes
            // live" broadcast to every agent — returns immediately instead of blocking on N synchronous
            // push-service round-trips. No queue worker needed (toady is deliberately worker-free).
            app()->terminating(fn () => PushSender::toUser($user, $payload));
        }
    }
}
