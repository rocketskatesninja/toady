<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Notifier;
use App\Support\PushSender;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** The push pipeline: Notifier (the one funnel) → PushSender (the one sender) → a fake push service. */
class PushSenderTest extends TestCase
{
    use RefreshDatabase;

    private function device(User $user, string $id = 'abc', ?array $keys = null, string $host = 'updates.push.services.mozilla.com'): PushSubscription
    {
        return PushSubscription::create(['user_id' => $user->id, 'endpoint' => "https://{$host}/wpush/v2/{$id}"] + ($keys ?? $this->pushKeys()));
    }

    private function message(User $user, string $type): array
    {
        return ['user' => $user, 'payload' => ['title' => 'Hi', 'body' => 'there']] + Notifier::TYPES[$type];
    }

    public function test_each_push_carries_its_types_ttl_and_urgency_with_modern_encryption(): void
    {
        $this->fakePushService([new Response(201), new Response(201)]);
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);
        $device = $this->device($user);

        $results = PushSender::send([$this->message($user, 'hacks'), $this->message($user, 'task')]);

        $this->assertSame([$device->id => 'ok'], $results);
        $this->assertCount(2, $this->pushRequests);
        [$hack, $task] = array_map(fn ($t) => $t['request'], $this->pushRequests);
        $this->assertSame(['300', 'high', 'aes128gcm'], [$hack->getHeaderLine('TTL'), $hack->getHeaderLine('Urgency'), $hack->getHeaderLine('Content-Encoding')]);
        $this->assertSame(['86400', 'normal'], [$task->getHeaderLine('TTL'), $task->getHeaderLine('Urgency')]);
    }

    public function test_a_gone_device_is_deleted_and_a_failure_is_logged_without_its_secret_url(): void
    {
        $this->fakePushService([new Response(410), new Response(500)]);
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);
        $gone = $this->device($user, 'gone');
        $flaky = $this->device($user, 'flaky');
        Log::spy();

        $results = PushSender::send([$this->message($user, 'dm')]);

        $this->assertSame([$gone->id => 'gone', $flaky->id => 'failed (500)'], $results);
        $this->assertModelMissing($gone);
        $this->assertModelExists($flaky);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $message === 'Web Push failed'
            && $context['status'] === 500 && $context['host'] === 'updates.push.services.mozilla.com'
            && ! str_contains(json_encode($context), 'wpush/v2'));
    }

    public function test_a_host_outside_the_allowlist_is_never_contacted(): void
    {
        $this->fakePushService([new Response(201)]);
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);
        $this->device($user, 'probe', host: 'internal.example');
        $real = $this->device($user);

        $this->assertSame([$real->id => 'ok'], PushSender::send([$this->message($user, 'dm')]));
        $this->assertCount(1, $this->pushRequests);
    }

    public function test_a_device_with_bad_keys_is_dropped_without_costing_the_others_their_push(): void
    {
        $this->fakePushService([new Response(201)]);
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);
        $broken = $this->device($user, 'broken', ['p256dh' => 'not-a-key', 'auth' => 'nope']);
        $good = $this->device($user);

        $this->assertSame([$broken->id => 'gone', $good->id => 'ok'], PushSender::send([$this->message($user, 'dm')]));
        $this->assertCount(1, $this->pushRequests);
        $this->assertModelMissing($broken);
    }

    public function test_a_payload_never_exceeds_what_every_browser_accepts(): void
    {
        $json = PushSender::encode(['title' => str_repeat('🔥', 500), 'body' => str_repeat('🦖', 2000), 'url' => '/ops/X']);

        $this->assertLessThanOrEqual(2820, strlen($json));
        $decoded = json_decode($json, true);
        $this->assertLessThanOrEqual(123, mb_strwidth($decoded['title'])); // cut to 120 columns + "..."
        $this->assertStringEndsWith('...', $decoded['body']);
        $this->assertSame('/ops/X', $decoded['url']);
    }

    public function test_without_vapid_keys_nothing_is_sent(): void
    {
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);
        $this->device($user);

        $this->assertSame([], PushSender::send([$this->message($user, 'dm')]));
    }

    public function test_notifier_queues_one_push_per_alert_with_its_types_delivery(): void
    {
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);

        Notifier::send($user, 'turn', 'Your turn', 'Go', '/ops/X');
        [$push] = Notifier::flush();

        $this->assertSame([900, 'high'], [$push['ttl'], $push['urgency']]);
        $this->assertSame(['title' => 'Your turn', 'body' => 'Go', 'url' => '/ops/X', 'tag' => 'turn-x', 'renotify' => true, 'silent' => false],
            array_diff_key($push['payload'], ['sent' => 1]));
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'turn')->count());
        $this->assertSame([], Notifier::flush()); // the outbox empties
    }

    public function test_a_moment_only_alert_pushes_without_a_feed_row_and_a_muted_type_does_neither(): void
    {
        $user = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL', 'notify_prefs' => ['task' => false]]);

        Notifier::send($user, 'hacks', 'Ready', null, '/hacks', null, 'hack-1', feed: false);
        Notifier::send($user, 'task', 'Assigned', 'x');

        $this->assertSame(['hacks'], array_map(fn ($p) => $p['payload']['tag'] === 'hack-1' ? 'hacks' : 'other', Notifier::flush()));
        $this->assertSame(0, Notification::count());
    }
}
