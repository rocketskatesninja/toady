<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('toady:verify-user {email : Email address of the account to mark verified}')]
#[Description("Mark an account's email as verified so it can get past the sign-in verification wall. Handy for a self-hosted instance with no SMTP, where an email+password signup can't be sent a verification link. (Google sign-ins are already auto-verified.)")]
class VerifyUser extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user) {
            $this->error("No account found for “{$email}”. Register the account first, then re-run this.");

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->info("“{$email}” is already verified — nothing to do.");

            return self::SUCCESS;
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->info("Verified “{$email}”. They can sign in and use the app now.");

        return self::SUCCESS;
    }
}
