<?php

namespace App\Support;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Encryption;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * The only code that talks to the browser push services. One call sends a whole batch concurrently (so one slow push
 * service can't hold up the rest), with each message's TTL + urgency, the current aes128gcm encryption, a payload that
 * can never exceed the size every browser accepts, and a log line for every failure — secret endpoint URLs redacted.
 * A device the push service says is gone (404/410) is deleted.
 */
class PushSender
{
    // Real browser push services. The endpoint a client registers is a URL the server later POSTs to
    // in-process, so it MUST be pinned to these hosts — otherwise any user could point it at an internal
    // host:port and turn a normal notification into a blind SSRF probe. An allowlist beats a denylist here.
    private const PUSH_HOSTS = ['fcm.googleapis.com', 'web.push.apple.com'];

    private const PUSH_HOST_SUFFIXES = ['.push.services.mozilla.com', '.notify.windows.com'];

    /** True only for an https URL whose host is a known browser push service. */
    public static function endpointAllowed(?string $endpoint): bool
    {
        if (! $endpoint) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        if (in_array($host, self::PUSH_HOSTS, true)) {
            return true;
        }
        foreach (self::PUSH_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A browser's push keys: p256dh = a 65-byte uncompressed P-256 point, auth = a 16-byte secret, both base64url.
     * Anything else makes the encryption throw mid-flush — and take every other push in the batch down with it.
     */
    public static function keysValid(?string $p256dh, ?string $auth): bool
    {
        $p = self::base64url($p256dh);
        $a = self::base64url($auth);

        return $p !== null && strlen($p) === 65 && $p[0] === "\x04" && $a !== null && strlen($a) === 16;
    }

    /** The payload as JSON, never larger than every browser accepts: title ≤120 chars, body ≤300, and no body at all if
     *  it would still be too big. Can't throw — a bad payload must never cost the user their alert. */
    public static function encode(array $payload): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $payload['title'] = Str::limit((string) ($payload['title'] ?? ''), 120);
        if (isset($payload['body'])) {
            $payload['body'] = Str::limit((string) $payload['body'], 300);
        }
        $json = json_encode($payload, $flags);
        if (strlen($json) > Encryption::MAX_COMPATIBILITY_PAYLOAD_LENGTH) {
            unset($payload['body']);
            $json = json_encode($payload, $flags);
        }

        return $json;
    }

    /**
     * Send each message to every device of its user, all at once. A message is
     * ['user' => User, 'payload' => array, 'ttl' => seconds, 'urgency' => 'high'|'normal'].
     * Returns [deviceId => 'ok' | 'gone' | 'failed (<status>)'].
     */
    public static function send(array $messages): array
    {
        $public = config('services.vapid.public');
        $private = config('services.vapid.private');
        if (! $public || ! $private || ! $messages) {
            return [];
        }

        $results = [];
        try {
            $devices = PushSubscription::whereIn('user_id', array_unique(array_map(fn ($m) => $m['user']->id, $messages)))
                ->get()->groupBy('user_id');
            $webPush = new WebPush(
                ['VAPID' => ['subject' => config('services.vapid.subject'), 'publicKey' => $public, 'privateKey' => $private]],
                [], null,
                ['timeout' => 5, 'connect_timeout' => 3] + (app()->bound('push.http') ? ['handler' => app('push.http')] : []),
            );

            $byEndpoint = [];
            foreach ($messages as $m) {
                foreach ($devices->get($m['user']->id, []) as $device) {
                    if (! self::endpointAllowed($device->endpoint)) {
                        continue; // defence-in-depth: never POST to a host that isn't a real push service
                    }
                    if (! self::keysValid($device->p256dh, $device->auth)) {
                        Log::error('Web Push device dropped: invalid keys', ['user' => $device->user_id, 'device' => $device->id]);
                        $device->delete();
                        $results[$device->id] = 'gone';

                        continue;
                    }
                    $webPush->queueNotification(
                        Subscription::create([
                            'endpoint' => $device->endpoint,
                            'keys' => ['p256dh' => $device->p256dh, 'auth' => $device->auth],
                            'contentEncoding' => 'aes128gcm',
                        ]),
                        self::encode($m['payload']),
                        ['TTL' => $m['ttl'], 'urgency' => $m['urgency']],
                    );
                    $byEndpoint[$device->endpoint] = $device;
                }
            }

            foreach ($webPush->flush() as $report) {
                if ($device = $byEndpoint[$report->getEndpoint()] ?? null) {
                    $results[$device->id] = self::settle($report, $device);
                }
            }
        } catch (\Throwable $e) {
            report($e); // never let a push failure break the request or the watcher
        }

        return $results;
    }

    /** Record one push service answer: delete a device that's gone, log anything else that failed. */
    private static function settle(MessageSentReport $report, PushSubscription $device): string
    {
        if ($report->isSuccess()) {
            return 'ok';
        }
        $status = $report->getResponse()?->getStatusCode();
        $context = ['user' => $device->user_id, 'device' => $device->id, 'host' => parse_url($device->endpoint, PHP_URL_HOST)];
        if (in_array($status, [404, 410], true)) {
            $device->delete();
            Log::info('Web Push device gone', $context);

            return 'gone';
        }
        // the reason quotes the endpoint URL — a device's secret — so redact URLs before logging
        Log::error('Web Push failed', $context + [
            'status' => $status ?? 'no response',
            'reason' => Str::limit((string) preg_replace('~https?://\S+~', '[endpoint]', $report->getReason()), 200),
        ]);

        return 'failed ('.($status ?? 'no response').')';
    }

    private static function base64url(?string $value): ?string
    {
        if (! $value || ! preg_match('/^[A-Za-z0-9_-]+=*$/', $value)) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
