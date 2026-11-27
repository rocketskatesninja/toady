<?php

namespace App\Http\Controllers;

use App\Models\MasterPortal;
use App\Models\OpWaypoint;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The master catalog is the owner's durable, hand-curated portal library. Only the owner
 * edits it; any operative can *read* it (the builder picker) to pull copies into an op.
 */
class CatalogController extends Controller
{
    private function ensureOwner(Request $request): void
    {
        abort_unless($request->user()->is_owner, 403);
    }

    /** Owner curation page. */
    public function index(Request $request): Response
    {
        $this->ensureOwner($request);

        $q = $request->string('q')->toString();
        $region = $request->string('region')->toString();
        $filter = $request->string('filter')->toString(); // '' | unverified | flagged | hidden | unnamed

        $portals = MasterPortal::query()
            ->search($q)->region($region)
            ->withCount(['contributions', 'flags'])
            ->when($filter === 'unverified', fn ($x) => $x->where('status', MasterPortal::UNVERIFIED))
            ->when($filter === 'flagged', fn ($x) => $x->has('flags'))
            ->when($filter === 'hidden', fn ($x) => $x->where('status', MasterPortal::HIDDEN))
            ->when($filter === 'unnamed', fn ($x) => $x->whereNull('title'))
            ->orderByRaw('title IS NULL, title')
            ->paginate(50)->withQueryString()
            ->through(fn (MasterPortal $p) => $this->summary($p));

        $regions = MasterPortal::query()
            ->selectRaw('region, COUNT(*) as n')->whereNotNull('region')
            ->groupBy('region')->orderByDesc('n')->get()
            ->map(fn ($r) => ['region' => $r->region, 'n' => $r->n]);

        return Inertia::render('Catalog/Index', [
            'portals' => $portals,
            'regions' => $regions,
            'focus' => $request->integer('focus') ?: null, // scroll to + highlight this portal (deep-link from the map overlay)
            'filters' => ['q' => $q, 'region' => $region, 'filter' => $filter],
            'total' => MasterPortal::count(),
            'counts' => [
                'unverified' => MasterPortal::where('status', MasterPortal::UNVERIFIED)->count(),
                'flagged' => MasterPortal::has('flags')->count(),
                'hidden' => MasterPortal::where('status', MasterPortal::HIDDEN)->count(),
                'unnamed' => MasterPortal::whereNull('title')->count(),
            ],
        ]);
    }

    /**
     * Read-only search for the op builder's picker (any operative). Given the op's whereabouts (lat/lng) the nearest
     * matches come first, each with its distance — the only way to tell one "Little Free Library" from another.
     * Names and positions only: field intel is copied in when a portal is actually added to a plan.
     */
    public function search(Request $request)
    {
        $at = $request->validate([
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);
        $near = isset($at['lat'], $at['lng']) ? [(float) $at['lat'], (float) $at['lng']] : null;

        return response()->json(
            MasterPortal::query()->visible()->whereNotNull('title')
                ->search($request->string('q')->toString())
                ->region($request->string('region')->toString())
                ->trustedFirst()
                ->when($near, fn ($q) => $q->closestTo(...$near), fn ($q) => $q->orderBy('title'))
                ->limit(40)
                ->get(['id', 'title', 'lat', 'lng', 'region', 'status'])
                ->map(fn (MasterPortal $p) => [
                    'id' => $p->id, 'title' => $p->title, 'region' => $p->region, 'status' => $p->status,
                    'meters' => $near ? (int) round(Geo::meters($near[0], $near[1], $p->lat, $p->lng)) : null,
                ])
        );
    }

    /** Community-confirmed catalog portals within the current map viewport — the map's "portals" overlay (tap to add to a plan). */
    public function inView(Request $request)
    {
        $b = $request->validate([
            'n' => ['required', 'numeric', 'between:-90,90'],
            's' => ['required', 'numeric', 'between:-90,90'],
            'e' => ['required', 'numeric', 'between:-180,180'],
            'w' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            MasterPortal::query()->visible()
                ->whereIn('status', [MasterPortal::VERIFIED, MasterPortal::OWNER_LOCKED]) // confirmed names only
                ->whereNotNull('lat')->whereNotNull('lng')
                ->whereBetween('lat', [min($b['s'], $b['n']), max($b['s'], $b['n'])])
                ->whereBetween('lng', [min($b['w'], $b['e']), max($b['w'], $b['e'])])
                ->closestTo(($b['n'] + $b['s']) / 2, ($b['e'] + $b['w']) / 2) // a crowded view keeps its middle, not a random 500
                ->limit(500)
                ->get(['id', 'guid', 'title', 'lat', 'lng', 'image'])
        );
    }

    /**
     * Owner import of an IITC export, sent by the catalog page in batches (the browser reads the file, so its size
     * never meets the upload limit). MasterPortal::import holds the rules for what an import may overwrite.
     */
    public function import(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        // answered as JSON either way — this app turns a failed validation into a redirect, which the page's
        // batch upload would silently follow
        $check = Validator::make($request->all(), [
            'source' => ['required', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:64'],
            'portals' => ['required', 'array', 'min:1', 'max:1000'],
            'portals.*.guid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'portals.*.title' => ['nullable', 'string', 'max:160'],
            'portals.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'portals.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'portals.*.image' => ['nullable', 'string', 'max:255', 'regex:#^https://[A-Za-z0-9.-]+\\.(googleusercontent|ggpht)\\.com/#'],
        ]);
        if ($check->fails()) {
            return response()->json(['message' => $check->errors()->first(), 'errors' => $check->errors()], 422);
        }
        $data = $check->validated();
        $region = $data['region'] ?? null;

        return response()->json(MasterPortal::import(
            array_map(fn ($p) => $p + ['region' => $region], $data['portals']), $data['source'],
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureOwner($request);
        $data = $this->validatePortal($request, true);
        $data['guid'] = 'manual:'.bin2hex(random_bytes(8));
        $data['source'] = 'manual';
        $data['first_seen'] = $data['last_seen'] = now()->toIso8601String();
        $portal = MasterPortal::create($data);

        return redirect()->route('catalog')->with('success', "Added “{$portal->title}”.");
    }

    public function update(Request $request, MasterPortal $portal): RedirectResponse
    {
        $this->ensureOwner($request);
        $portal->update($this->validatePortal($request, false));

        return back()->with('success', "Updated “{$portal->title}”.");
    }

    public function destroy(Request $request, MasterPortal $portal): RedirectResponse
    {
        $this->ensureOwner($request);
        $portal->delete();

        return redirect()->route('catalog')->with('success', 'Portal removed.');
    }

    /** Bless the current name: owner-locked, frozen from consensus and community-hide. */
    public function lock(Request $request, MasterPortal $portal): RedirectResponse
    {
        $this->ensureOwner($request);
        $portal->update(['status' => MasterPortal::OWNER_LOCKED]);

        return back()->with('success', "Locked “{$portal->title}”.");
    }

    /** Un-hide a disputed portal: clear its flags and mark it verified again. */
    public function restore(Request $request, MasterPortal $portal): RedirectResponse
    {
        $this->ensureOwner($request);
        $portal->flags()->delete();
        $portal->update(['status' => MasterPortal::VERIFIED]);

        return back()->with('success', "Restored “{$portal->title}”.");
    }

    /** @return array<string, mixed> */
    private function validatePortal(Request $request, bool $isNew): array
    {
        return $request->validate([
            'title' => [$isNew ? 'required' : 'sometimes', 'nullable', 'string', 'max:160'],
            'lat' => [$isNew ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'lng' => [$isNew ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'region' => ['nullable', 'string', 'max:64'],
            ...OpWaypoint::intelRules(),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(MasterPortal $p): array
    {
        return [
            'id' => $p->id, 'guid' => $p->guid, 'title' => $p->title,
            'lat' => $p->lat, 'lng' => $p->lng, 'region' => $p->region,
            ...$p->only(array_keys(OpWaypoint::INTEL)),
            'has_intel' => $p->hasIntel(),
            'status' => $p->status,
            'source' => $p->source,
            'contributors' => $p->contributions_count ?? 0,
            'flags' => $p->flags_count ?? 0,
        ];
    }
}
