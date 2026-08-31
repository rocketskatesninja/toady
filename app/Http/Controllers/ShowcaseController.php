<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Showcase;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShowcaseController extends Controller
{
    /** Public gallery of ops built with toady — agent-submitted stories + photos, curated by an admin. */
    public function index(): Response
    {
        abort_unless((bool) Setting::get('showcase_enabled', true), 404);

        $entries = Showcase::where('published', true)->latest()->get();
        $users = User::whereIn('id', $entries->flatMap(fn ($e) => $e->tagged_ids ?? [])->unique())
            ->get(['id', 'callsign', 'faction'])->keyBy('id');

        return Inertia::render('Showcase', [
            'submitEmail' => config('services.toady.owner_email'),
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'story' => $e->story,
                'credit' => $e->credit,
                'date' => $e->created_at?->toFormattedDateString(),
                'images' => collect($e->images ?? [])->keys()
                    ->map(fn ($i) => route('showcase.image', ['showcase' => $e->id, 'index' => $i]))->all(),
                'tagged' => collect($e->tagged_ids ?? [])->map(fn ($id) => $users->get($id))->filter()
                    ->map(fn ($u) => ['callsign' => $u->callsign, 'faction' => $u->faction])->values(),
            ]),
        ]);
    }

    /** Stream one of a published entry's images (public, no auth). */
    public function image(Showcase $showcase, int $index): StreamedResponse
    {
        abort_unless((bool) Setting::get('showcase_enabled', true), 404);
        abort_unless($showcase->published, 404);
        $path = ($showcase->images ?? [])[$index] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        // set the type explicitly (we send nosniff, so the browser won't guess) — covers uploads + demo SVGs
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', default => 'image/jpeg',
        };

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $mime, 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
