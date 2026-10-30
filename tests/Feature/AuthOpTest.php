<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AuthOpTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogle(string $id, string $email): void
    {
        $u = new SocialiteUser();
        $u->id = $id;
        $u->email = $email;
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_new_google_user_is_created_verified_and_sent_to_onboarding(): void
    {
        $this->fakeGoogle('g1', 'a@b.com');
        $this->get('/auth/google/callback')->assertRedirect(route('onboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['google_id' => 'g1', 'email' => 'a@b.com', 'callsign' => null]);
    }

    public function test_configured_owner_email_is_flagged_is_owner(): void
    {
        config(['services.toady.owner_email' => 'owner@example.com']);
        $this->fakeGoogle('g-owner', 'Owner@Example.com');
        $this->get('/auth/google/callback');
        $this->assertDatabaseHas('users', ['google_id' => 'g-owner', 'is_owner' => true]);
    }

    public function test_email_registration_creates_unverified_user_and_sends_verification(): void
    {
        Notification::fake();

        $this->post('/register', ['email' => 'new@agent.test', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertRedirect(route('verification.notice'));

        $user = \App\Models\User::where('email', 'new@agent.test')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_login_works(): void
    {
        $this->mkUser(['email' => 'p@p.com', 'password' => 'password123', 'callsign' => 'Pilot', 'faction' => 'ENL']);
        $this->post('/login', ['email' => 'p@p.com', 'password' => 'password123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_onboarding_sets_callsign_and_faction(): void
    {
        $user = $this->mkUser(['email' => 'c@d.com']);
        $this->actingAs($user)->post('/onboard', ['callsign' => 'Maverick', 'faction' => 'RES'])
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'callsign' => 'Maverick', 'faction' => 'RES']);
    }

    public function test_anyone_can_create_an_op_and_becomes_operative(): void
    {
        $user = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $this->actingAs($user)->post('/ops', ['name' => 'Brunswick Fan', 'type' => 'any_order'])->assertRedirect();

        $op = Op::where('name', 'Brunswick Fan')->first();
        $this->assertSame($user->id, $op->owner_id);
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $user->id, 'role' => 'operative']);
    }

    public function test_join_link_adds_an_agent(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'TOKEN123']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        $agent = $this->mkUser(['callsign' => 'Grunt', 'faction' => 'ENL']);
        $this->actingAs($agent)->get('/j/TOKEN123')->assertRedirect(route('ops.show', $op));
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id, 'role' => 'agent']);
    }

    public function test_guest_join_link_remembers_token_and_redirects_to_login(): void
    {
        $this->get('/j/SOMETOKEN')
            ->assertRedirect(route('login'))
            ->assertSessionHas('join_token', 'SOMETOKEN');
    }

    public function test_logout_keeps_the_account(): void
    {
        $user = $this->mkUser(['callsign' => 'Anchor', 'faction' => 'ENL']);
        $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
