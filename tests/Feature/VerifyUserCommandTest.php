<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_an_unverified_account_case_insensitively(): void
    {
        $user = User::create(['email' => 'nova@example.com', 'password' => 'password123']);
        $this->assertFalse($user->hasVerifiedEmail());

        $this->artisan('toady:verify-user', ['email' => 'NOVA@example.com'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_it_is_idempotent_for_an_already_verified_account(): void
    {
        User::create(['email' => 'seen@example.com', 'password' => 'password123', 'email_verified_at' => now()]);

        $this->artisan('toady:verify-user', ['email' => 'seen@example.com'])
            ->assertSuccessful();
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('toady:verify-user', ['email' => 'ghost@example.com'])
            ->assertFailed();
    }
}
