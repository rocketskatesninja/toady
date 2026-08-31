<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Showcase;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ShowcaseController extends Controller
{
    /** The admin gallery manager: list entries + the user list for the tag picker. */
    public function index(): Response
    {
        return Inertia::render('Admin/Showcase', [
            'enabled' => (bool) Setting::get('showcase_enabled', true),
            'entries' => Showcase::latest()->get()->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'story' => $e->story,
                'credit' => $e->credit,
                'published' => $e->published,
                'tagged_ids' => $e->tagged_ids ?? [],
                'date' => $e->created_at?->toFormattedDateString(),
                'images' => collect($e->images ?? [])->keys()
                    ->map(fn ($i) => route('showcase.image', ['showcase' => $e->id, 'index' => $i]))->all(),
            ]),
            'users' => User::orderBy('callsign')->get(['id', 'callsign', 'faction']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Showcase::create($this->validated($request) + ['images' => $this->storeImages($request)]);

        return back()->with('success', 'Showcase entry added.');
    }

    public function update(Request $request, Showcase $showcase): RedirectResponse
    {
        $data = $this->validated($request);

        // reconcile photos: keep the existing ones still selected, delete the rest, append any new uploads (cap 3)
        $retained = [];
        $keep = array_map('intval', (array) $request->input('keep', []));
        foreach ($showcase->images ?? [] as $i => $path) {
            if (in_array($i, $keep, true)) {
                $retained[] = $path;
            } else {
                Storage::disk('local')->delete($path);
            }
        }
        $data['images'] = array_slice(array_merge($retained, $this->storeImages($request)), 0, 5);

        $showcase->update($data);

        return back()->with('success', 'Showcase entry updated.');
    }

    public function destroy(Showcase $showcase): RedirectResponse
    {
        $this->deleteImages($showcase);
        $showcase->delete();

        return back()->with('success', 'Showcase entry removed.');
    }

    /** Turn the public /showcase page itself on or off (entries stay intact either way). */
    public function updateEnabled(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        Setting::put('showcase_enabled', $enabled);

        return back()->with('success', $enabled ? 'Public showcase page enabled.' : 'Public showcase page disabled.');
    }

    /** @return array{title:string,story:?string,credit:?string,tagged_ids:list<int>,published:bool} */
    private function validated(Request $request): array
    {
        $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'story' => ['nullable', 'string', 'max:5000'],
            'credit' => ['nullable', 'string', 'max:120'],
            'tagged_ids' => ['nullable', 'array', 'max:30'],
            'tagged_ids.*' => ['integer', 'exists:users,id'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
            'keep' => ['nullable', 'array'],            // existing image indices to retain (update only)
            'keep.*' => ['integer', 'min:0'],
        ]);

        return [
            'title' => $request->string('title')->toString(),
            'story' => $request->input('story'),
            'credit' => $request->input('credit'),
            'tagged_ids' => array_values(array_map('intval', (array) $request->input('tagged_ids', []))),
            'published' => $request->boolean('published'),
        ];
    }

    /** @return list<string> */
    private function storeImages(Request $request): array
    {
        $paths = [];
        foreach ((array) $request->file('images', []) as $file) {
            $paths[] = $file->store('showcase', 'local');
        }

        return $paths;
    }

    private function deleteImages(Showcase $showcase): void
    {
        foreach ($showcase->images ?? [] as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
