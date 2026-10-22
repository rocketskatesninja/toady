<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'callsign' => $user->callsign,
                    'faction' => $user->faction,
                    'is_owner' => (bool) $user->is_owner,
                    'is_admin' => (bool) $user->is_admin,
                    'show_reference' => (bool) $user->show_reference,
                    'notify_prefs' => $user->notify_prefs,
                ] : null,
            ],
            'unreadNotifications' => $user && $user->callsign
                ? fn () => \App\Models\Notification::where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
            'vapidPublicKey' => config('services.vapid.public'),
            'maps' => ['traffic' => (bool) config('services.tomtom.key')],
            'donateUrl' => config('services.donate.url'),
            'envBadge' => config('app.env_badge'),
            'showcaseEnabled' => fn () => (bool) \App\Models\Setting::get('showcase_enabled', true),
            'cycle' => $user ? fn () => \App\Models\Setting::get('cycle') : null, // global cycle-timing anchor (authed only; the widget is dashboard-only)
            'mu_density' => $user ? fn () => (float) \App\Models\Setting::get('mu_density', 375) : null, // people/km² for the MU field-scoring estimate (admin-tunable per region)
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
