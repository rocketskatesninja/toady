<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google OAuth sign-in (one of two; email/password is the other). No invite gate — anyone can
 * sign in. A brand-new user is created persistent + email-verified (Google vouches for the email)
 * and sent to onboarding to pick a callsign/faction.
 */
class GoogleController extends Controller
{
    public function redirect(): RedirectResponse
    {
        // Always show Google's account chooser, so a signed-out user can pick a different account
        // instead of being silently re-authed into the last one.
        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $g = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect()->route('home')->with('error', 'Google sign-in failed or was cancelled.');
        }

        $googleId = (string) $g->getId();
        $email = mb_strtolower(trim((string) $g->getEmail()));
        if ($googleId === '') {
            return redirect()->route('home')->with('error', 'Google returned no usable account.');
        }

        $user = User::where('google_id', $googleId)->first();
        if (! $user && $email !== '') {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if (! $user) {
            $user = User::create([
                'google_id' => $googleId,
                'email' => $email ?: null,
                'email_verified_at' => now(),
            ]);
            if ($email !== '' && $email === mb_strtolower((string) config('services.toady.owner_email'))) {
                $user->forceFill(['is_owner' => true])->save();
            }
        } elseif (! $user->google_id) {
            $user->forceFill(['google_id' => $googleId])->save();
        }
        // Google vouches for the address → a Google-authenticated account is always verified.
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if ($user->suspended_at !== null) {
            return redirect()->route('home')->with('error', 'This account has been suspended.');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        // Came in via a join link? Send them to join after authenticating.
        if ($token = $request->session()->pull('join_token')) {
            return redirect()->route('ops.join', $token);
        }

        if (! $user->callsign) {
            return redirect()->route('onboard');
        }

        return redirect()->intended(route('dashboard'));
    }
}
