<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    use AuthorizesOpAccess;

    /** Opt-in live location for an op. Polled clients read it from the op payload. */
    public function update(Request $request, Op $op): JsonResponse
    {
        $this->requireParticipant($op, $request->user());

        $data = $request->validate([
            'sharing' => ['required', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0'],
        ]);

        $op->presence()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'sharing' => $data['sharing'],
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
                'last_seen' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }
}
