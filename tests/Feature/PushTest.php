<?php

namespace Tests\Feature;

use App\Support\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_then_unsubscribe(): void
    {
        $u = $this->mkUser(['google_id' => 'p', 'callsign' => 'P', 'faction' => 'ENL']);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

        $this->actingAs($u)->postJson('/push/subscribe', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'pk', 'auth' => 'ak'],
        ])->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $u->id, 'endpoint' => $endpoint, 'p256dh' => 'pk']);

        // re-subscribing the same endpoint updates in place (no duplicate)
        $this->actingAs($u)->postJson('/push/subscribe', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'pk2', 'auth' => 'ak2'],
        ])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);

        $this->actingAs($u)->postJson('/push/unsubscribe', ['endpoint' => $endpoint])->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $endpoint]);
    }

    /** The guard that keeps the push endpoint (a URL the server later POSTs to) pinned to real push services. */
    public function test_endpoint_allowlist_rejects_non_push_hosts(): void
    {
        foreach ([
            'http://127.0.0.1:6379/',                     // loopback (Redis)
            'https://169.254.169.254/latest/meta-data',   // cloud metadata
            'http://fcm.googleapis.com/fcm/send/abc',      // right host, not https
            'https://evil.example.com/x',                  // arbitrary host
            'https://fcm.googleapis.com.attacker.com/x',   // suffix-spoof
            'not a url', '', null,
        ] as $bad) {
            $this->assertFalse(PushSender::endpointAllowed($bad), "should reject: ".var_export($bad, true));
        }

        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc',
            'https://updates.push.services.mozilla.com/wpush/v2/xyz',
            'https://web.push.apple.com/abc',
            'https://xyz.notify.windows.com/w/?token=abc',
        ] as $good) {
            $this->assertTrue(PushSender::endpointAllowed($good), "should allow: {$good}");
        }
    }

    /** End to end: a subscribe with an SSRF-y endpoint never persists a subscription. */
    public function test_subscribe_never_stores_a_non_push_endpoint(): void
    {
        $u = $this->mkUser(['google_id' => 'q', 'callsign' => 'Q', 'faction' => 'RES']);

        foreach (['http://127.0.0.1:6379/', 'https://169.254.169.254/', 'https://evil.example.com/x'] as $bad) {
            $this->actingAs($u)->post('/push/subscribe', [
                'endpoint' => $bad, 'keys' => ['p256dh' => 'pk', 'auth' => 'ak'],
            ]); // Inertia app: a validation failure redirects back — the point is nothing is stored
        }
        $this->assertDatabaseCount('push_subscriptions', 0);

        $this->actingAs($u)->postJson('/push/subscribe', [
            'endpoint' => 'https://web.push.apple.com/abc', 'keys' => ['p256dh' => 'pk', 'auth' => 'ak'],
        ])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }
}
