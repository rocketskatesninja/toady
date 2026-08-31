<?php

namespace App\Support;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Fire-and-forget Web Push to a user's devices. Silently no-ops if VAPID isn't configured
 * or the user has no subscriptions; prunes subscriptions the push service has expired.
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

    public static function toUser(User $user, array $payload): void
    {
        $public = config('services.vapid.public');
        $private = config('services.vapid.private');
        if (! $public || ! $private) {
            return;
        }

        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.vapid.subject'),
                'publicKey' => $public,
                'privateKey' => $private,
            ]]);

            foreach ($subs as $s) {
                if (! self::endpointAllowed($s->endpoint)) {
                    continue; // defence-in-depth: never POST to a host that isn't a real push service
                }
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $s->endpoint,
                        'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth],
                    ]),
                    json_encode($payload)
                );
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    $code = $report->getResponse()?->getStatusCode();
                    if (in_array($code, [404, 410], true)) {
                        PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);   // never let a push failure break the request
        }
    }
}
