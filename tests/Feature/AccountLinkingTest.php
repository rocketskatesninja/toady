<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/** Signing in with Google must never leave a pre-registered password (or the owner flag) in a stranger's hands. */
class AccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogle(string $id, string $email, bool $verified = true): void
    {
        $u = new SocialiteUser;
        $u->id = $id;
        $u->email = $email;
        $u->user = ['email_verified' => $verified];
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_google_sign_in_takes_over_an_unverified_pre_registered_account_and_kills_its_password(): void
    {
        // an attacker registers the victim's address with their own password (never verified)
        $this->post('/register', ['email' => 'Victim@Gmail.com', 'password' => 'attacker-pass', 'password_confirmation' => 'attacker-pass']);
        $this->post('/logout');
        $stub = User::where('email', 'victim@gmail.com')->firstOrFail();
        $this->assertNull($stub->email_verified_at);

        $this->fakeGoogle('g-victim', 'victim@gmail.com');
        $this->get('/auth/google/callback');

        $stub->refresh();
        $this->assertSame('g-victim', $stub->google_id);
        $this->assertNotNull($stub->email_verified_at);
        $this->assertNull($stub->password); // the attacker's password is gone

        $this->post('/logout');
        $this->post('/login', ['email' => 'victim@gmail.com', 'password' => 'attacker-pass']);
        $this->assertGuest();
    }

    public function test_a_verified_password_account_keeps_its_password_when_google_is_linked(): void
    {
        $user = User::create(['email' => 'me@gmail.com', 'password' => 'my-own-pass', 'email_verified_at' => now()]);

        $this->fakeGoogle('g-me', 'me@gmail.com');
        $this->get('/auth/google/callback');

        $this->assertTrue(Hash::check('my-own-pass', $user->fresh()->password));
        $this->assertSame('g-me', $user->fresh()->google_id);
    }

    public function test_an_unverified_google_address_cannot_claim_an_existing_account(): void
    {
        $user = User::create(['email' => 'me@example.com', 'password' => 'my-own-pass', 'email_verified_at' => now()]);

        $this->fakeGoogle('g-other', 'me@example.com', verified: false);
        $this->get('/auth/google/callback');

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_the_owner_flag_needs_a_proven_address(): void
    {
        config(['services.toady.owner_email' => 'owner@example.com']);

        $this->post('/register', ['email' => 'owner@example.com', 'password' => 'squatter-pass', 'password_confirmation' => 'squatter-pass']);
        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertFalse((bool) $user->is_owner); // registering alone proves nothing

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->grantOwnerIfOwnerEmail();
        $this->assertTrue((bool) $user->fresh()->is_owner);
    }

    public function test_registering_a_differently_cased_existing_email_is_a_validation_error_not_a_crash(): void
    {
        User::create(['email' => 'taken@example.com', 'password' => 'x', 'email_verified_at' => now()]);

        $this->post('/register', ['email' => 'Taken@Example.com', 'password' => 'another-pass', 'password_confirmation' => 'another-pass'])
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_a_password_reset_signs_other_devices_out(): void
    {
        config(['session.driver' => 'database']);
        $user = User::create(['email' => 'me@example.com', 'password' => 'old-pass-123', 'email_verified_at' => now()]);
        \DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $token = Password::createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => 'me@example.com', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123']);

        $this->assertTrue(Hash::check('new-pass-123', $user->fresh()->password));
        $this->assertSame(0, \DB::table('sessions')->where('user_id', $user->id)->count());
    }
}
