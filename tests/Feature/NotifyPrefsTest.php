<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotifyPrefsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_muted_type_is_not_delivered(): void
    {
        $user = $this->mkUser(['callsign' => 'Muted', 'faction' => 'ENL']);
        $user->update(['notify_prefs' => ['task' => false]]);

        Notifier::send($user->fresh(), 'task', 'New task', 'x', null, null, false);
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());

        // a type left on still delivers
        Notifier::send($user->fresh(), 'dm', 'New DM', 'x', null, null, false);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_user_saves_notification_prefs(): void
    {
        $user = $this->mkUser(['callsign' => 'Pref', 'faction' => 'ENL']);
        $this->actingAs($user)->put('/profile', ['notify_prefs' => ['task' => false, 'vibrate' => false]])->assertRedirect();

        $prefs = $user->fresh()->notify_prefs;
        $this->assertFalse($prefs['task']);
        $this->assertFalse($prefs['vibrate']);
    }

    public function test_user_toggles_broadcast_email_opt_out_from_their_profile(): void
    {
        $user = $this->mkUser(['callsign' => 'Mail', 'faction' => 'ENL', 'email' => 'mail@x.com']);

        // opt out of broadcast emails
        $this->actingAs($user)->put('/profile', ['email_opt_out' => true])->assertRedirect();
        $this->assertTrue((bool) $user->fresh()->email_opt_out);

        // opt back in (re-subscribe) — the path the unsubscribe page can't offer
        $this->actingAs($user)->put('/profile', ['email_opt_out' => false])->assertRedirect();
        $this->assertFalse((bool) $user->fresh()->email_opt_out);
    }
}
