<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        if (! Auth::attempt(['email' => mb_strtolower($data['email']), 'password' => $data['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Those credentials don’t match our records.']);
        }
        if (Auth::user()->suspended_at !== null) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account has been suspended.']);
        }
        $request->session()->regenerate();

        if ($token = $request->session()->pull('join_token')) {
            return redirect()->route('ops.join', $token);
        }

        return redirect()->intended(route('dashboard'));
    }
}
