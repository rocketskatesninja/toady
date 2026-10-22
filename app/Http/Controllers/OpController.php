<?php

namespace App\Http\Controllers;

use App\Dashboard\OpWidgets;
use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\MasterPortal;
use App\Models\Notification;
use App\Models\Op;
use App\Models\OpParticipant;
use App\Models\OpWaypoint;
use App\Models\Presence;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpController extends Controller
{
    use AuthorizesOpAccess;

    /** Ops the user is part of (created or joined), newest first. */
    public function dashboard(Request $request): Response
    {
        $user = $request->user();

        // unread notifications per op (internal op_id → count) for the card counters
        $unreadByOp = Notification::where('user_id', $user->id)->whereNull('read_at')->whereNotNull('op_id')
            ->selectRaw('op_id, COUNT(*) as n')->groupBy('op_id')->pluck('n', 'op_id');

        $ops = Op::whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', '!=', 'closed')
            ->withCount('participants')
            // load only the viewer's own participant row so roleFor()/isOperative() resolve from memory (no N+1)
            ->with(['participants' => fn ($q) => $q->where('user_id', $user->id)])
            ->with(['waypoints' => fn ($q) => $q->whereNotNull('lat')->select('id', 'op_id', 'lat', 'lng')])
            ->latest()
            ->latest('id') // deterministic tiebreak when created_at collides (bulk/imported ops)
            ->get()
            // agents see planning ops they've been added to as well — shown as "upcoming" (opening one lands
            // on a standing-by screen until the operative flips it to active)
            ->map(fn (Op $op) => [
                'id' => $op->public_id,
                'name' => $op->name,
                'status' => $op->status,
                'type' => $op->type,
                'role' => $op->roleFor($user),
                'participants' => $op->participants_count,
                'is_operative' => $op->isOperative($user),
                'unread' => (int) ($unreadByOp[$op->id] ?? 0), // unread notifications for the card badge
                'created' => $op->created_at?->toIso8601String(),
                // placed waypoints ([lat, lng] pairs) for the card's static map thumbnail + dots
                'wps' => $op->waypoints->map(fn ($w) => [round((float) $w->lat, 6), round((float) $w->lng, 6)])->values(),
            ])
            ->values();

        // Apply the user's saved manual card order. Ops absent from the saved list (brand-new, or never
        // reordered) sort to -1 → they keep their latest-first position at the top; the stable sort holds
        // the saved arrangement for the rest.
        $savedOrder = $user->dashboard_layout['op_order'] ?? [];
        if (is_array($savedOrder) && $savedOrder) {
            $pos = array_flip($savedOrder);
            $ops = $ops->sortBy(fn ($o) => $pos[$o['id']] ?? -1)->values();
        }

        return Inertia::render('Dashboard', ['ops' => $ops]);
    }

    /** Anyone can create an op and becomes its operative. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Op::TYPES)],
        ]);

        $op = Op::create([
            'owner_id' => $request->user()->id,
            'name' => trim($data['name'] ?? '') ?: 'New op',
            'type' => $data['type'] ?? 'any_order',
            'status' => 'planning',
            'join_token' => Op::freshToken(),
        ]);
        $op->participants()->create(['user_id' => $request->user()->id, 'role' => OpParticipant::ROLE_OPERATIVE]);

        return redirect()->route('ops.show', [$op, 'new' => 1]); // ?new=1 → the op page opens the edit modal
    }

    public function show(Request $request, Op $op): Response
    {
        $user = $request->user();
        $this->requireParticipant($op, $user);

        // a planning op is a draft hidden from agents — they get a "standing by" screen until it goes active
        if ($op->status === 'planning' && ! $op->isOperative($user)) {
            return Inertia::render('Ops/Show', [
                'op' => [
                    'id' => $op->public_id, 'name' => $op->name, 'status' => $op->status, 'type' => $op->type,
                    'owner' => $op->owner->callsign, 'is_operative' => false, 'my_role' => $op->roleFor($user),
                    'description' => null, 'goals' => null, 'notes' => null, 'allow_export' => false, 'join_token' => null,
                ],
                'waiting' => true,
                'participants' => [], 'banned' => [], 'waypoints' => [], 'steps' => [], 'templates' => [], 'keyHoldings' => [], 'presence' => [], 'sharing' => false,
                'layouts' => OpWidgets::layoutsFor($user, $op->public_id), 'widgetCatalog' => OpWidgets::meta(),
            ]);
        }

        $viewerIsOperative = $op->isOperative($user);
        $op->load([
            'owner:id,callsign',
            'participants.user' => fn ($q) => $q->select('id', 'callsign', 'faction', 'avatar', 'updated_at', 'phone', 'telegram', 'preferred_contact')->withCount(['participations', 'pushSubscriptions']),
            'waypoints', 'steps.assignee:id,callsign,faction', 'keyHoldings.user:id,callsign,faction',
            // only hydrate LIVE presence (sharing + fresh) — filtered in SQL, not by loading every row then dropping stale ones in PHP
            'presence' => fn ($q) => $q->where('sharing', true)->where('last_seen', '>', now()->subSeconds(Presence::STALE_SECONDS))->with('user:id,callsign,faction'),
        ]);

        // Sequential "hidden" ops: agents see only up to the current waypoint; everything past it is
        // redacted server-side so it can't be read via the API. Operatives always see the full plan.
        $waypoints = $op->waypoints;
        $opSteps = $op->steps;
        $keyHoldings = $op->keyHoldings;
        $hiddenWaypoints = 0;
        if ($op->type === 'hidden' && ! $viewerIsOperative
            && ($visibleIds = Op::visibleWaypointIds($waypoints, $opSteps)) !== null) {
            $hiddenWaypoints = $waypoints->count() - $visibleIds->count();
            $waypoints = $waypoints->filter(fn ($w) => $visibleIds->contains($w->id))->values();
            $opSteps = $opSteps->filter(fn ($s) => $s->op_waypoint_id === null || $visibleIds->contains($s->op_waypoint_id))->values();
            $keyHoldings = $keyHoldings->filter(fn ($h) => $visibleIds->contains($h->op_waypoint_id))->values();
        }

        // shared-catalog trust for each placed portal, batched by GUID (one query for the whole plan)
        $catByGuid = MasterPortal::whereIn('guid', $waypoints->pluck('guid')->filter()->unique()->values())
            ->withCount('contributions')->get()->keyBy('guid');

        $steps = $opSteps->map(fn ($s) => [
            'id' => $s->id, 'phase' => $s->phase, 'text' => $s->text, 'action' => $s->action,
            'op_waypoint_id' => $s->op_waypoint_id, 'assignee_id' => $s->assignee_id,
            'assignee' => $s->assignee?->callsign, 'assignee_faction' => $s->assignee?->faction, 'resos' => $s->resos, 'mods' => $s->mods, 'qty' => $s->qty,
            'links' => $s->links, 'notes' => $s->notes, 'done' => $s->done, 'done_by' => $s->done_by,
            'done_at' => $s->done_at?->toIso8601String(),
            'mine' => $s->assignee_id === $user->id,
        ])->values();

        return Inertia::render('Ops/Show', [
            'op' => [
                'id' => $op->public_id,
                'name' => $op->name,
                'description' => $op->description,
                'type' => $op->type,
                'status' => $op->status,
                'goals' => $op->goals,
                'notes' => $op->notes,
                'allow_export' => $op->allow_export,
                // the invite token is roster-control credential — operatives only
                'join_token' => $viewerIsOperative ? $op->join_token : null,
                'owner' => $op->owner->callsign,
                'owner_id' => $op->owner_id,
                'is_operative' => $viewerIsOperative,
                'my_role' => $op->roleFor($user),
                'hidden_waypoints' => $hiddenWaypoints, // count redacted ahead of the agent (hidden ops)
                'shared_notes' => $op->shared_notes,    // operator-shared op-wide notes (everyone reads; polled live)
                // depth of the undo stack — operatives only, and only while editable (planning)
                'undo_count' => $viewerIsOperative && $op->status === 'planning' ? $op->undoSnapshots()->count() : 0,
            ],
            'participants' => $op->participants->map(fn ($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'callsign' => $p->user->callsign,
                'faction' => $p->user->faction,
                'avatar' => $p->user->avatarUrl(),
                'role' => $p->role,
                'color' => $p->color, // operator-assigned per-op colour (beacon/route/ring)
                // only the OPT-IN contact info a user added — never their Google identity email
                'phone' => $p->user->phone,
                'telegram' => $p->user->telegram,
                'preferred' => $p->user->preferred_contact,
                // operative-only intel about the agent
                'joined' => $viewerIsOperative ? $p->created_at?->toIso8601String() : null,
                'ops_count' => $viewerIsOperative ? $p->user->participations_count : null,
                'push' => $viewerIsOperative ? ($p->user->push_subscriptions_count > 0) : null, // has push notifications enabled?
            ]),
            // banned agents — operative-only intel; the roster surfaces an "unban" action per row
            'banned' => $viewerIsOperative
                ? $op->bans()->with(['user:id,callsign,faction', 'bannedBy:id,callsign'])->latest()->get()->map(fn ($b) => [
                    'user_id' => $b->user_id,
                    'callsign' => $b->user?->callsign,
                    'faction' => $b->user?->faction,
                    'banned_by' => $b->bannedBy?->callsign,
                    'at' => $b->created_at?->toIso8601String(),
                ])->values()
                : [],
            'waypoints' => $waypoints->map(function ($w) use ($catByGuid) {
                $cat = $w->guid ? ($catByGuid[$w->guid] ?? null) : null;

                return [
                    'id' => $w->id, 'seq' => $w->seq, 'role' => $w->role, 'keys_needed' => $w->keys_needed,
                    'title' => $w->title, 'lat' => $w->lat, 'lng' => $w->lng, 'image' => $w->image,
                    // op-local portal intel (operative-editable overlay; purged with the op)
                    'gate_pin' => $w->gate_pin, 'parking' => $w->parking, 'hours' => $w->hours,
                    'access_notes' => $w->access_notes, 'hazards' => $w->hazards,
                    // shared-catalog trust for this portal's name (null when the waypoint isn't catalog-linked)
                    'catalog_status' => $cat?->status,
                    'catalog_sources' => $cat?->contributions_count,
                ];
            }),
            'keyHoldings' => $keyHoldings->map(fn ($h) => [
                'op_waypoint_id' => $h->op_waypoint_id, 'user_id' => $h->user_id,
                'callsign' => $h->user?->callsign, 'faction' => $h->user?->faction, 'qty' => $h->qty,
                'updated_at' => $h->updated_at?->toIso8601String(),
            ]),
            'steps' => $steps,
            // the viewer's OWN reusable directive templates (operatives only — they're the ones who apply them)
            'templates' => $viewerIsOperative ? $user->stepTemplates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'count' => count($t->steps)]) : [],
            'presence' => $op->presence->map(fn ($p) => [ // already constrained to live rows in the eager-load
                'user_id' => $p->user_id, 'callsign' => $p->user->callsign, 'faction' => $p->user->faction,
                'lat' => $p->lat, 'lng' => $p->lng,
            ])->values(),
            'sharing' => (bool) optional($op->presence->firstWhere('user_id', $user->id))->sharing,
            'waiting' => false,
            // the viewer's own private scratchpad for this op (never anyone else's)
            'myNotes' => $op->participants->firstWhere('user_id', $user->id)?->notes,
            // the viewer's own synced BYOK AI config {provider,key,model}, if they opted into cross-device sync (else null)
            'aiConfig' => $user->ai_config,
            // customizable widget dashboard (per-op, per-user; desktop + mobile)
            'layouts' => OpWidgets::layoutsFor($user, $op->public_id, $viewerIsOperative),
            'widgetCatalog' => OpWidgets::meta($viewerIsOperative),
        ]);
    }

    /** Operative edits the op's directives. */
    public function update(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $prevStatus = $op->status;
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => ['sometimes', Rule::in(Op::TYPES)],
            'goals' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'allow_export' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['planning', 'active', 'complete'])],
        ]);

        // Title, briefing, and mission type are locked once the op leaves planning — only export + status stay editable.
        if ($op->status !== 'planning') {
            unset($data['name'], $data['description'], $data['goals'], $data['type'], $data['notes']);
        }

        $op->update($data);
        $newStatus = $data['status'] ?? null;
        $others = fn () => $op->participants()->where('user_id', '!=', $request->user()->id)->with('user')->get();

        // flipping to active is the "go" signal — push every agent
        if ($prevStatus !== 'active' && $newStatus === 'active') {
            $others()->each(fn ($p) => $p->user && Notifier::send($p->user, 'go', "🟢 {$op->name} is live",
                'The op is active — open it for your directives and go when it’s your turn.', "/ops/{$op->public_id}", $op->id, tag: "op-{$op->id}-go"));
        }
        // marking complete — let the team know the op wrapped
        if ($prevStatus !== 'complete' && $newStatus === 'complete') {
            $op->notifyComplete($request->user()->id, 'The operator marked this op complete — nice work out there.');
        }

        // confirm a status flip back to the operator who made it (a bare edit / no-op flashes nothing)
        $flash = ($newStatus && $newStatus !== $prevStatus) ? match ($newStatus) {
            'active' => "“{$op->name}” is now live — agents notified.",
            'complete' => "“{$op->name}” marked complete.",
            'planning' => "“{$op->name}” is back to planning — edits unlocked.",
            default => null,
        } : null;

        return $flash ? back()->with('success', $flash) : back();
    }

    /** Close the op — purges every byte of it (FK cascade). Operative only. */
    public function close(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $op->delete();

        return redirect()->route('dashboard')->with('success', 'Op closed and purged.');
    }

    /** Export the plan (directives + waypoints incl. intel) as a reusable JSON file. */
    public function export(Request $request, Op $op): JsonResponse
    {
        $user = $request->user();
        $isOperative = $op->isOperative($user);
        abort_unless($isOperative || ($op->allow_export && $op->roleFor($user) !== null), 403);

        $op->load(['waypoints', 'steps.assignee']);
        $waypoints = $op->waypoints;
        $steps = $op->steps;

        // a hidden op redacts future waypoints from non-operatives in show(); apply the same here so export
        // can't be used to pull the whole future plan an agent isn't meant to see yet
        if ($op->type === 'hidden' && ! $isOperative
            && ($visibleIds = Op::visibleWaypointIds($waypoints, $steps)) !== null) {
            $waypoints = $waypoints->filter(fn ($w) => $visibleIds->contains($w->id))->values();
            $steps = $steps->filter(fn ($s) => $s->op_waypoint_id === null || $visibleIds->contains($s->op_waypoint_id))->values();
        }

        if ($request->query('format') === 'iitc') {
            return $this->iitcDrawTools($op, $waypoints, $steps);
        }

        $seqOf = $waypoints->pluck('seq', 'id');   // op_waypoint_id → seq

        $plan = [
            'toady_plan' => 1,
            'name' => $op->name,
            'description' => $op->description,
            'type' => $op->type,
            'goals' => $op->goals,
            'notes' => $op->notes,
            'waypoints' => $waypoints->map(fn ($w) => [
                'seq' => $w->seq, 'role' => $w->role, 'title' => $w->title, 'lat' => $w->lat, 'lng' => $w->lng,
                'keys_needed' => $w->keys_needed,
                'gate_pin' => $w->gate_pin, 'access_notes' => $w->access_notes, 'parking' => $w->parking,
                'hours' => $w->hours, 'hazards' => $w->hazards,
            ])->values(),
            'steps' => $steps->map(fn ($s) => [
                'phase' => $s->phase, 'seq' => $s->seq, 'text' => $s->text, 'action' => $s->action,
                'resos' => $s->resos, 'mods' => $s->mods, 'qty' => $s->qty, 'notes' => $s->notes,
                // link target(s) and assignee by stable references (waypoint seq + agent callsign) so they re-map on import
                'link_seqs' => collect($s->links ?? [])->map(fn ($id) => $seqOf[$id] ?? null)->filter(fn ($v) => $v !== null)->values(),
                'assignee' => $s->assignee?->callsign,
                'waypoint_seq' => $s->op_waypoint_id ? ($seqOf[$s->op_waypoint_id] ?? null) : null,
            ])->values(),
        ];

        return response()->json($plan, 200, [
            'Content-Disposition' => 'attachment; filename="'.Str::slug($op->name ?: 'op').'-plan.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Export the placed portals as IITC Draw Tools markers + the planned links as polylines, so the plan
     *  drops straight onto the Intel map. Lat/lng based (no portal GUIDs), so titles aren't carried — the
     *  geometry is. Same access + hidden-op redaction as the toady-format export above. */
    private function iitcDrawTools(Op $op, $waypoints, $steps): JsonResponse
    {
        $placed = $waypoints->filter(fn ($w) => $w->lat !== null && $w->lng !== null)->keyBy('id');
        $items = [];

        foreach ($placed as $w) {
            $items[] = ['type' => 'marker', 'latLng' => ['lat' => (float) $w->lat, 'lng' => (float) $w->lng], 'color' => '#a24ac3'];
        }
        foreach ($steps as $s) {
            if ($s->action !== 'link' || empty($s->links)) {
                continue;
            }
            $from = $placed->get($s->op_waypoint_id);
            if (! $from) {
                continue;
            }
            foreach ($s->links as $targetId) {
                if (! ($to = $placed->get($targetId))) {
                    continue;
                }
                $items[] = ['type' => 'polyline', 'color' => '#ff6600', 'latLngs' => [
                    ['lat' => (float) $from->lat, 'lng' => (float) $from->lng],
                    ['lat' => (float) $to->lat, 'lng' => (float) $to->lng],
                ]];
            }
        }

        return response()->json($items, 200, [
            'Content-Disposition' => 'attachment; filename="'.Str::slug($op->name ?: 'op').'-iitc.json"',
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Op notes. scope 'mine' = a participant's private scratchpad; scope 'op' = operator-shared notes the
     *  whole op reads. Both are purged with the op. */
    public function saveNotes(Request $request, Op $op)
    {
        $user = $request->user();
        abort_unless($op->roleFor($user) !== null, 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:10000'],
            'scope' => ['nullable', Rule::in(['mine', 'op'])],
        ]);
        $text = $data['notes'] ?: null;

        if (($data['scope'] ?? 'mine') === 'op') {
            abort_unless($op->isOperative($user), 403); // only operators share op-wide notes
            $op->update(['shared_notes' => $text]);
        } else {
            $op->participants()->where('user_id', $user->id)->update(['notes' => $text]);
        }

        return response()->noContent();
    }

    /** Create a new op (you become operative) from an exported plan file/paste. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'plan' => ['nullable', 'string', 'max:1000000'],
            'file' => ['nullable', 'file', 'mimetypes:application/json,text/plain', 'max:2048'],
        ]);

        $json = $request->filled('plan')
            ? $request->input('plan')
            : ($request->hasFile('file') ? $request->file('file')->get() : null);
        $plan = json_decode((string) $json, true);

        if (! is_array($plan) || empty($plan['name'])) {
            throw ValidationException::withMessages(['plan' => 'That is not a valid toady plan.']);
        }

        $op = Op::create([
            'owner_id' => $request->user()->id,
            'name' => mb_substr((string) $plan['name'], 0, 120),
            'description' => $plan['description'] ?? null,
            'type' => in_array($plan['type'] ?? '', Op::TYPES, true) ? $plan['type'] : 'any_order',
            'goals' => $plan['goals'] ?? null,
            'notes' => $plan['notes'] ?? null,
            'status' => 'planning',
            'join_token' => Op::freshToken(),
        ]);
        $op->participants()->create(['user_id' => $request->user()->id, 'role' => OpParticipant::ROLE_OPERATIVE]);

        $wpBySeq = [];
        foreach (array_slice((array) ($plan['waypoints'] ?? []), 0, 500) as $w) {
            if (! is_array($w) || (empty($w['title']) && ! isset($w['lat'], $w['lng']))) {
                continue; // need a name or coordinates — but keep unplaced (named) locations
            }
            $wp = $op->waypoints()->create([
                'seq' => (int) ($w['seq'] ?? 0),
                'role' => in_array($w['role'] ?? '', OpWaypoint::ROLES, true) ? $w['role'] : 'waypoint',
                'title' => $w['title'] ?? null, 'lat' => $w['lat'] ?? null, 'lng' => $w['lng'] ?? null,
                'keys_needed' => max(0, (int) ($w['keys_needed'] ?? 0)),
                'gate_pin' => $w['gate_pin'] ?? null, 'access_notes' => $w['access_notes'] ?? null,
                'parking' => $w['parking'] ?? null, 'hours' => $w['hours'] ?? null, 'hazards' => $w['hazards'] ?? null,
            ]);
            if (isset($w['seq'])) {
                $wpBySeq[$w['seq']] = $wp->id;
            }
        }
        // named agents → their accounts (callsign is unique). Pre-assigns directives so a re-run with the
        // same team has the right agent on each task once they join; unknown callsigns just stay unassigned.
        $assigneeByCallsign = User::whereIn('callsign', collect($plan['steps'] ?? [])->pluck('assignee')->filter()->unique())
            ->pluck('id', 'callsign');

        foreach (array_slice((array) ($plan['steps'] ?? []), 0, 2000) as $s) {
            if (empty($s['text']) && empty($s['action'])) {
                continue; // a directive needs an objective and/or a comment — but keep objective-only ones
            }
            $links = collect($s['link_seqs'] ?? [])->map(fn ($seq) => $wpBySeq[$seq] ?? null)->filter()->values()->all();
            $op->steps()->create([
                'phase' => in_array($s['phase'] ?? '', ['prep', 'run'], true) ? $s['phase'] : 'run',
                'seq' => (int) ($s['seq'] ?? 0),
                'text' => ! empty($s['text']) ? mb_substr((string) $s['text'], 0, 240) : null,
                'action' => $s['action'] ?? null,
                'op_waypoint_id' => isset($s['waypoint_seq']) ? ($wpBySeq[$s['waypoint_seq']] ?? null) : null,
                'assignee_id' => isset($s['assignee']) ? ($assigneeByCallsign[$s['assignee']] ?? null) : null,
                'resos' => $s['resos'] ?? null, 'mods' => $s['mods'] ?? null,
                'qty' => isset($s['qty']) ? (max(0, (int) $s['qty']) ?: null) : null,
                'links' => $links ?: null,
                'notes' => $s['notes'] ?? null,
            ]);
        }

        return redirect()->route('ops.show', $op)->with('success', "Imported “{$op->name}”.");
    }

    /** Join via the operative's shared link. Guests are sent through Google first. */
    public function join(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        // Guests sign in first (any method), then get bounced back here.
        if (! $user) {
            $request->session()->put('join_token', $token);

            return redirect()->route('login');
        }
        // Finish verification / onboarding first, carrying the pending join.
        if (! $user->hasVerifiedEmail()) {
            $request->session()->put('join_token', $token);

            return redirect()->route('verification.notice');
        }
        if (! $user->callsign) {
            $request->session()->put('join_token', $token);

            return redirect()->route('onboard');
        }

        $op = Op::where('join_token', $token)->first();
        if (! $op || $op->status === 'closed') {
            return redirect()->route('dashboard')->with('error', 'That op link is invalid or the op has closed.');
        }
        if ($op->isBanned($user)) {
            return redirect()->route('dashboard')->with('error', 'You are banned from this op.');
        }

        $p = $op->participants()->firstOrCreate(['user_id' => $user->id], ['role' => OpParticipant::ROLE_AGENT]);

        // First-time join (not a rejoin) → tell the operative(s).
        if ($p->wasRecentlyCreated) {
            $op->operativeRecipients()->each(fn (User $u) => Notifier::send(
                $u, 'join', "{$user->callsign} joined · {$op->name}", 'A new agent is on the roster.', "/ops/{$op->public_id}?view=roster", $op->id));
        }

        return redirect()->route('ops.show', $op)->with('success', "Joined “{$op->name}”.");
    }
}
