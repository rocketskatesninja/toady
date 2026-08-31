<?php

namespace App\Http\Controllers;

use App\Support\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // the endpoint is a URL the server later POSTs to — pin it to real push services so it can't be
        // pointed at an internal host:port (SSRF).
        if (! PushSender::endpointAllowed($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'Unsupported push endpoint.']);
        }

        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string']]);
        $request->user()->pushSubscriptions()->where('endpoint', $request->input('endpoint'))->delete();

        return response()->json(['ok' => true]);
    }
}
