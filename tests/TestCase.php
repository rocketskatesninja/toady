<?php

namespace Tests;

use App\Models\User;
use App\Support\Notifier;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Minishlink\WebPush\VAPID;

abstract class TestCase extends BaseTestCase
{
    /** Requests the fake push service received (see fakePushService). */
    protected array $pushRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        // tests never reach a real push service: no VAPID keys unless a test fakes the service (a local .env may have real ones)
        config(['services.vapid.public' => null, 'services.vapid.private' => null]);
        Notifier::flush(); // and never inherit pushes another test queued
    }

    /** A persistent, email-verified user (the default state for everyone who's signed in). */
    protected function mkUser(array $attrs = []): User
    {
        return User::create(array_merge(['email_verified_at' => now()], $attrs));
    }

    /** A browser's push keys, for real: p256dh = an uncompressed P-256 public point, auth = 16 random bytes (base64url). */
    protected function pushKeys(): array
    {
        $ec = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'p256dh' => $b64("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            'auth' => $b64(random_bytes(16)),
        ];
    }

    /** Configure VAPID and answer every Web Push with the given responses in order; requests land in $pushRequests. */
    protected function fakePushService(array $responses): void
    {
        $keys = VAPID::createVapidKeys();
        config(['services.vapid' => ['public' => $keys['publicKey'], 'private' => $keys['privateKey'], 'subject' => 'mailto:test@toady.test']]);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->pushRequests));
        $this->app->instance('push.http', $stack);
    }
}
