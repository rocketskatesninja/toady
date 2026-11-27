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
            'callsign' => User::callsignRules($user->id),
            'faction' => ['required', Rule::in(['ENL', 'RES'])],
        ]);

        $user->update(['callsign' => $data['callsign'], 'faction' => $data['faction']]);

        // resume a pending join if they arrived via an invite link
        return $this->pendingJoin($request) ?? redirect()->intended(route('dashboard'))->with('success', "Deployed, {$user->callsign}.");
    }
}
