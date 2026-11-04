<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

/**
 * The one funnel for every alert: checks the recipient's switch for the type, records a feed row (unless it's a
 * moment-only alert like a hack timer running out), and queues a Web Push to all of their devices. Pushes go out in one
 * batch after the response (or when the hack-alert watcher flushes each second), so no request ever waits on a push
 * service. Callers are responsible for not notifying a user about their own action.
 */
class Notifier
{
    /**
     * Each alert type's push delivery. Time-critical alerts are sent at high urgency (wakes a dozing phone at once) and
     * expire fast — an alert that would arrive minutes late is dropped instead; the feed row keeps the record.
     */
    public const TYPES = [
        'hacks' => ['urgency' => 'high', 'ttl' => 300],      // a hack timer ran out
        'turn' => ['urgency' => 'high', 'ttl' => 900],       // your turn in a sequential op
        'go' => ['urgency' => 'high', 'ttl' => 900],         // op live / complete
        'dm' => ['urgency' => 'high', 'ttl' => 3600],
        'mention' => ['urgency' => 'high', 'ttl' => 3600],
        'task' => ['urgency' => 'normal', 'ttl' => 86400],   // a directive assigned to you
        'done' => ['urgency' => 'normal', 'ttl' => 86400],
        'join' => ['urgency' => 'normal', 'ttl' => 86400],
        'keys' => ['urgency' => 'normal', 'ttl' => 86400],
        'report' => ['urgency' => 'normal', 'ttl' => 86400],
    ];

    /** Pushes waiting for the next flush. */
    private static array $outbox = [];

    /** Every notify_prefs key: one switch per alert type, plus buzz. */
    public static function prefKeys(): array
    {
        return [...array_keys(self::TYPES), 'buzz'];
    }

    /** The user's switch for one key — an alert type, or buzz (missing = on). */
    public static function allows(User $user, string $key): bool
    {
        return (($user->notify_prefs ?? [])[$key] ?? true) !== false;
    }

    public static function send(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?int $opId = null,
        ?string $tag = null,
        bool $feed = true,
    ): void {
        if (! self::allows($user, $type)) {
            return;
        }

        if ($feed) {
            Notification::create([
                'user_id' => $user->id,
                'op_id' => $opId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ]);
        }

        if (self::$outbox === []) {
            app()->terminating(fn () => self::flush()); // after the response — no request waits on a push service
        }
        self::$outbox[] = [
            'user' => $user,
            'ttl' => self::TYPES[$type]['ttl'],
            'urgency' => self::TYPES[$type]['urgency'],
            'payload' => array_filter([
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'tag' => $tag ?? $type.'-'.($opId ?? 'x'), // repeats of the same kind collapse into one tray entry…
                'renotify' => true,                         // …yet each still alerts
                'silent' => $type === 'hacks' && ! self::allows($user, 'hacks_buzz'),
                'sent' => (int) (microtime(true) * 1000),
            ], fn ($v) => $v !== null),
        ];
    }

    /** Send everything queued, as one batch. Returns the batch (what the tests inspect). */
    public static function flush(): array
    {
        [$batch, self::$outbox] = [self::$outbox, []];
        if ($batch) {
            PushSender::send($batch);
        }

        return $batch;
    }
}
