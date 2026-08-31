<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_can_be_requested(): void
    {
        Notification::fake();
        $user = $this->mkUser(['email' => 'r@r.com', 'password' => 'oldpassword1', 'callsign' => 'R']);

        $this->post('/forgot-password', ['email' => 'r@r.com'])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_the_token(): void
    {
        Notification::fake();
        $user = $this->mkUser(['email' => 'r2@r.com', 'password' => 'oldpassword1', 'callsign' => 'R2']);
        $this->post('/forgot-password', ['email' => 'r2@r.com']);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => 'r2@r.com',
                'password' => 'newpassword1',
                'password_confirmation' => 'newpassword1',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password));
    }
}
