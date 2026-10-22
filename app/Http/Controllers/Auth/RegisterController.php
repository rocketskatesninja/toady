<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $email = mb_strtolower($data['email']);

        $user = User::create([
            'email' => $email,
            'password' => $data['password'],
        ]);
        if ($email === mb_strtolower((string) config('services.toady.owner_email'))) {
            $user->forceFill(['is_owner' => true])->save();
        }

        event(new Registered($user));   // sends the verification email
        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
