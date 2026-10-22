<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OnboardController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->callsign) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Onboard');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'callsign' => ['required', 'string', 'regex:'.User::CALLSIGN_REGEX, function ($attr, $value, $fail) use ($user) {
                if (User::callsignTaken((string) $value, $user->id)) {
                    $fail('That codename is already taken.');
                }
            }],
            'faction' => ['required', Rule::in(['ENL', 'RES'])],
        ], ['callsign.regex' => User::CALLSIGN_MESSAGE]);

        $user->update(['callsign' => $data['callsign'], 'faction' => $data['faction']]);

        // resume a pending join if they arrived via an invite link
        if ($token = $request->session()->pull('join_token')) {
            return redirect()->route('ops.join', $token);
        }

        return redirect()->intended(route('dashboard'))->with('success', "Deployed, {$user->callsign}.");
    }
}
