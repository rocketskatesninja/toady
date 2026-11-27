<?php

namespace App\Http\Controllers;

use App\Dashboard\OpWidgets;
use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\HackTimer;
use App\Models\MasterPortal;
use App\Models\Notification;
use App\Models\Op;
use App\Models\OpParticipant;
use App\Models\OpWaypoint;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $ops = Op::liveFor($user)
            ->withCount('participants')
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
                'participants' => $op->participants_count,
                'is_operative' => $op->isOperative($user),
                'unread' => (int) ($unreadByOp[$op->id] ?? 0), // unread notifications for the card badge
                'created' => $op->created_at?->toIso8601String(),
                // placed waypoints ([lat, lng] pairs) for the card's static map thumbnail + dots
                // — never for a plan this viewer can't open yet (a draft, or a hidden-type op's future stops)
                'wps' => $op->isDraftFor($user) || ($op->type === 'hidden' && ! $op->isOperative($user)) ? []
                    : $op->waypoints->map(fn ($w) => [round((float) $w->lat, 6), round((float) $w->lng, 6)])->values(),
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

        $op = Op::createFor($request->user(), [
            'name' => trim($data['name'] ?? '') ?: 'New op',
            'type' => $data['type'] ?? 'any_order',
        ]);

        return redirect()->route('ops.show', [$op, 'new' => 1]); // ?new=1 → the op page opens the edit modal
    }

    public function show(Request $request, Op $op): Response
    {
        $user = $request->user();
        // This runs every ~3 seconds for everyone on the op (the live poll), so it's kept lean: the roster is
        // loaded once up front and reused for the access checks and for every "whose row is this" lookup below,
        // and anything the poll doesn't ask for is a closure Inertia never runs.
        $op->load('participants');
        $this->requireParticipant($op, $user);

        // a planning op is a draft hidden from agents — they get a "standing by" screen until it goes active
        if ($op->isDraftFor($user)) {
            return Inertia::render('Ops/Show', [
                'op' => [
                    'id' => $op->public_id, 'name' => $op->name, 'status' => $op->status, 'type' => $op->type,
                    'owner' => $op->owner->callsign, 'is_operative' => false,
                    'description' => null, 'goals' => null, 'notes' => null, 'allow_export' => false, 'join_token' => null,
                ],
                'waiting' => true,
                'participants' => [], 'banned' => [], 'waypoints' => [], 'steps' => [], 'tasks' => [], 'templates' => [], 'keyHoldings' => [], 'hackTimers' => [], 'presence' => [], 'sharing' => false,
                'layouts' => OpWidgets::layoutsFor($user, $op->public_id), 'widgetCatalog' => OpWidgets::meta(),
            ]);
        }

        $viewerIsOperative = $op->isOperative($user);
        $op->participants->load(['user' => fn ($q) => $q
            ->select('id', 'callsign', 'faction', 'avatar', 'updated_at', 'phone', 'telegram', 'preferred_contact', 'emergency_contact')
            ->when($viewerIsOperative, fn ($q) => $q->withCount(['participations', 'pushSubscriptions']))]); // operator-only roster intel
        $op->load([
            'waypoints', 'steps', 'keyHoldings', 'tasks',
            // only hydrate LIVE presence (sharing + fresh) — filtered in SQL, not by loading every row then dropping stale ones in PHP
            'presence' => fn ($q) => $q->live(),
        ]);
        // Everyone a row can name is on the roster (leaving the op removes or frees their rows) — except an
        // imported plan's pre-assigned agent who hasn't joined yet, or whoever checked a task off and then left.
        $people = $op->participants->pluck('user', 'user_id');
        $strangers = $op->steps->pluck('assignee_id')->merge($op->tasks->pluck('assignee_id'))->merge($op->tasks->pluck('done_by'))
            ->filter()->unique()->diff($people->keys());
        if ($strangers->isNotEmpty()) {
            $people = $people->union(User::whereIn('id', $strangers)->get(['id', 'callsign', 'faction'])->keyBy('id'));
        }
        $callsign = fn (?int $id) => $id ? $people->get($id)?->callsign : null;

        [$waypoints, $opSteps, $visibleIds] = $op->visiblePlan($viewerIsOperative);
        $keyHoldings = $visibleIds ? $op->keyHoldings->filter(fn ($h) => $visibleIds->contains($h->op_waypoint_id))->values() : $op->keyHoldings;
        $hiddenWaypoints = $visibleIds ? $op->waypoints->count() - $visibleIds->count() : 0;

        // shared-catalog trust for each placed portal, batched by GUID (one query for the whole plan)
        $guids = $waypoints->pluck('guid')->filter()->unique()->values();
        $catByGuid = $guids->isEmpty() ? collect() : MasterPortal::whereIn('guid', $guids)->withCount('contributions')->get()->keyBy('guid');
        // depth of the undo stack + what Undo would reverse — operatives only, and only while editable (planning)
        $undoLabels = $viewerIsOperative && $op->status === 'planning' ? $op->undoSnapshots()->latest('id')->pluck('label') : collect();

        $steps = $opSteps->map(fn ($s) => [
            'id' => $s->id, 'phase' => $s->phase, 'text' => $s->text, 'action' => $s->action,
            'op_waypoint_id' => $s->op_waypoint_id, 'assignee_id' => $s->assignee_id,
            'assignee' => $callsign($s->assignee_id), 'resos' => $s->resos, 'mods' => $s->mods, 'qty' => $s->qty,
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
                'owner' => $callsign($op->owner_id),
                'owner_id' => $op->owner_id,
                'is_operative' => $viewerIsOperative,
                'hidden_waypoints' => $hiddenWaypoints, // count redacted ahead of the agent (hidden ops)
                'shared_notes' => $op->shared_notes,    // operator-shared op-wide notes (everyone reads; polled live)
                'undo_count' => $undoLabels->count(),
                // what Undo would reverse ("deleted portal “Lighthouse”") — null for snapshots taken before labels existed
                'undo_label' => $undoLabels->first(),
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
                // operative-only intel about the agent — incl. who to call if something happens to them in the field
                'emergency' => $viewerIsOperative ? $p->user->emergency_contact : null,
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
                    ...$w->only(array_keys(OpWaypoint::INTEL)),
                    // shared-catalog trust for this portal's name (null when the waypoint isn't catalog-linked)
                    'catalog_status' => $cat?->status,
                    'catalog_sources' => $cat?->contributions_count,
                ];
            }),
            'keyHoldings' => $keyHoldings->map(fn ($h) => [
                'op_waypoint_id' => $h->op_waypoint_id, 'user_id' => $h->user_id,
                'callsign' => $callsign($h->user_id), 'faction' => $people->get($h->user_id)?->faction, 'qty' => $h->qty,
                'updated_at' => $h->updated_at?->toIso8601String(),
            ]),
            // the viewer's OWN hack timers on this op's portals — never anyone else's
            'hackTimers' => HackTimer::where('user_id', $user->id)->where('op_id', $op->id)
                ->when($visibleIds, fn ($q) => $q->whereIn('op_waypoint_id', $visibleIds))
                ->latest('id')->get() // op + portals are already loaded above — reuse them, no extra queries
                ->map(fn (HackTimer $t) => $t->setRelation('op', $op)->setRelation('waypoint', $op->waypoints->find($t->op_waypoint_id))->toPayload()),
            'steps' => $steps,
            // the Tasks widget's checklist (everyone on the op sees all of it)
            'tasks' => $op->tasks->map(fn ($t) => [
                'id' => $t->id, 'text' => $t->text, 'assignee_id' => $t->assignee_id, 'assignee' => $callsign($t->assignee_id),
                'done' => $t->done, 'done_by' => $callsign($t->done_by),
            ])->values(),
            // the viewer's OWN reusable directive templates (operatives only — they're the ones who apply them)
            'templates' => fn () => $viewerIsOperative ? $user->stepTemplates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'count' => count($t->steps)]) : [],
            'presence' => $op->presence->map(fn ($p) => [ // already constrained to live rows in the eager-load
                'user_id' => $p->user_id, 'callsign' => $callsign($p->user_id), 'faction' => $people->get($p->user_id)?->faction,
                'lat' => $p->lat, 'lng' => $p->lng,
            ])->values(),
            'sharing' => (bool) optional($op->presence->firstWhere('user_id', $user->id))->sharing,
            'waiting' => false,
            // the viewer's own private scratchpad for this op (never anyone else's)
            'myNotes' => fn () => $op->participants->firstWhere('user_id', $user->id)?->notes,
            // the viewer's own synced BYOK AI config {provider,key,model}, if they opted into cross-device sync (else null)
            'aiConfig' => fn () => $user->ai_config, // decrypted — so never on a poll
            // customizable widget dashboard (per-op, per-user; desktop + mobile)
            'layouts' => fn () => OpWidgets::layoutsFor($user, $op->public_id, $viewerIsOperative),
            'widgetCatalog' => fn () => OpWidgets::meta($viewerIsOperative),
        ]);
    }

    /** Placed waypoints keyed by id, and the plan's link directives between them as [from, to, thrown?] —
     *  shared by the map overlay's outline and the IITC export. */
    private function placedLinks(Collection $waypoints, Collection $steps): array
    {
        $placed = $waypoints->filter(fn ($w) => $w->lat !== null && $w->lng !== null)->keyBy('id');
        $links = [];
        foreach ($steps as $s) {
            if ($s->action !== 'link' || ! is_array($s->links) || ! ($from = $placed->get($s->op_waypoint_id))) {
                continue;
            }
            foreach ($s->links as $id) {
                if ($to = $placed->get($id)) {
                    $links[] = [$from, $to, (bool) $s->done];
                }
            }
        }

        return [$placed, $links];
    }

    /** The viewer's other live ops that can be laid over this op's map (the map's overlay filter — operatives only). */
    public function overlays(Request $request, Op $op): JsonResponse
    {
        $user = $request->user();
        $this->requireParticipant($op, $user);
        abort_unless($op->isOperative($user), 404);

        $ops = Op::liveFor($user)
            ->whereKeyNot($op->id)
            ->withCount(['waypoints' => fn ($q) => $q->whereNotNull('lat')])
            ->latest('id')
            ->get()
            ->filter(fn ($o) => ! $o->isDraftFor($user)) // a draft is hidden from its agents
            ->map(fn ($o) => ['id' => $o->public_id, 'name' => $o->name, 'status' => $o->status, 'portals' => $o->waypoints_count])
            ->values();

        return response()->json($ops);
    }

    /**
     * An op's bare map shape — placed portals + planned links — for laying it over another op's map. Only what
     * the viewer could see on this op's own page (same rules as show()); no notes, roster, keys, or intel.
     */
    public function outline(Request $request, Op $op): JsonResponse
    {
        $user = $request->user();
        $this->requireLive($op, $user);
        $operative = $op->isOperative($user);

        [$waypoints, $steps] = $op->visiblePlan($operative);
        [$placed, $links] = $this->placedLinks($waypoints, $steps);

        return response()->json([
            'id' => $op->public_id, 'name' => $op->name, 'status' => $op->status,
            'portals' => $placed->values()->map(fn ($w) => [
                'id' => $w->id, 'title' => $w->title, 'lat' => $w->lat, 'lng' => $w->lng,
            ]),
            'links' => array_map(fn ($l) => [$l[0]->id, $l[1]->id, $l[2]], $links),
        ]);
    }

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
        // Undo is for plan edits. Once the op goes live its snapshots are dropped — restoring one later would
        // roll back the run itself (check-offs and reported keys live in the same rows).
        if ($prevStatus === 'planning' && $newStatus && $newStatus !== 'planning') {
            $op->undoSnapshots()->delete();
        }
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
        $this->requireLive($op, $user); // members only, and never an agent's way into a draft
        $isOperative = $op->isOperative($user);
        abort_unless($isOperative || $op->allow_export, 403);

        // the same hidden-op redaction as the op page, so export can't pull a future plan an agent isn't meant to see
        $op->load(['waypoints', 'steps.assignee']);
        [$waypoints, $steps] = $op->visiblePlan($isOperative);

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
                ...$w->only(array_keys(OpWaypoint::INTEL)),
            ])->values(),
            'steps' => $steps->map(fn ($s) => [
                'phase' => $s->phase, 'seq' => $s->seq, 'text' => $s->text, 'action' => $s->action,
                'resos' => $s->resos, 'mods' => $s->mods, 'qty' => $s->qty, 'notes' => $s->notes,
                // link target(s) and assignee by stable references (waypoint seq + agent callsign) so they re-map on import
                'link_seqs' => collect($s->links ?? [])->map(fn ($id) => $seqOf[$id] ?? null)->filter(fn ($v) => $v !== null)->values(),
                'assignee' => $s->assignee?->callsign,
                'waypoint_seq' => $seqOf[$s->op_waypoint_id] ?? null,
            ])->values(),
        ];

        return response()->json($plan, 200, [
            'Content-Disposition' => 'attachment; filename="'.Str::slug($op->name ?: 'op').'-plan.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Export the placed portals as IITC Draw Tools markers + the planned links as polylines, so the plan
     *  drops straight onto the Intel map. Lat/lng based (no portal GUIDs), so titles aren't carried — the
     *  geometry is. Same access + hidden-op redaction as the toady-format export above. */
    private function iitcDrawTools(Op $op, Collection $waypoints, Collection $steps): JsonResponse
    {
        [$placed, $links] = $this->placedLinks($waypoints, $steps);
        $at = fn ($w) => ['lat' => (float) $w->lat, 'lng' => (float) $w->lng];
        $items = [
            ...$placed->values()->map(fn ($w) => ['type' => 'marker', 'latLng' => $at($w), 'color' => '#a24ac3']),
            ...array_map(fn ($l) => ['type' => 'polyline', 'color' => '#ff6600', 'latLngs' => [$at($l[0]), $at($l[1])]], $links),
        ];

        return response()->json($items, 200, [
            'Content-Disposition' => 'attachment; filename="'.Str::slug($op->name ?: 'op').'-iitc.json"',
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Op notes. scope 'mine' = a participant's private scratchpad; scope 'op' = operator-shared notes the
     *  whole op reads. Both are purged with the op. */
    public function saveNotes(Request $request, Op $op)
    {
        $user = $request->user();
        $this->requireParticipant($op, $user);

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

        // A plan file is outside input like any form: every value is bounded the way the edit forms bound it,
        // and the whole import lands or none of it does.
        $str = fn ($v, int $max) => is_scalar($v) && (string) $v !== '' ? mb_substr((string) $v, 0, $max) : null;
        $coord = fn ($v, float $limit) => is_numeric($v) && abs((float) $v) <= $limit ? (float) $v : null;
        $seqOf = fn ($v) => is_int($v) || is_string($v) ? $v : null;

        $op = DB::transaction(function () use ($request, $plan, $str, $coord, $seqOf) {
            $op = Op::createFor($request->user(), [
                'name' => $str($plan['name'], 120) ?? 'Imported op',
                'description' => $str($plan['description'] ?? null, 2000),
                'type' => in_array($plan['type'] ?? '', Op::TYPES, true) ? $plan['type'] : 'any_order',
                'goals' => $str($plan['goals'] ?? null, 2000),
                'notes' => $str($plan['notes'] ?? null, 2000),
            ]);

            $wpBySeq = [];
            foreach (array_slice((array) ($plan['waypoints'] ?? []), 0, 500) as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $lat = $coord($w['lat'] ?? null, 90);
                $lng = $coord($w['lng'] ?? null, 180);
                $title = $str($w['title'] ?? null, 160);
                if ($title === null && ($lat === null || $lng === null)) {
                    continue; // need a name or coordinates — but keep unplaced (named) locations
                }
                $wp = $op->waypoints()->create([
                    'seq' => (int) ($w['seq'] ?? 0),
                    'role' => in_array($w['role'] ?? '', OpWaypoint::ROLES, true) ? $w['role'] : 'waypoint',
                    'title' => $title, 'lat' => $lng === null ? null : $lat, 'lng' => $lat === null ? null : $lng,
                    'keys_needed' => min(9999, max(0, (int) ($w['keys_needed'] ?? 0))),
                    ...collect(OpWaypoint::INTEL)->map(fn (int $max, string $field) => $str($w[$field] ?? null, $max))->all(),
                ]);
                if (($seq = $seqOf($w['seq'] ?? null)) !== null) {
                    $wpBySeq[$seq] = $wp->id;
                }
            }
            // named agents → their accounts (callsign is unique). Pre-assigns directives so a re-run with the
            // same team has the right agent on each task once they join; unknown callsigns just stay unassigned.
            $steps = array_filter(array_slice((array) ($plan['steps'] ?? []), 0, 2000), 'is_array');
            $assigneeByCallsign = User::whereIn('callsign', collect($steps)->pluck('assignee')->filter(fn ($c) => is_string($c))->unique())
                ->pluck('id', 'callsign');

            foreach ($steps as $s) {
                $text = $str($s['text'] ?? null, 240);
                $action = in_array($s['action'] ?? null, OpStepController::ACTIONS, true) ? $s['action'] : null;
                // a directive needs an objective and/or a comment, and a location in this plan — one that
                // points nowhere would be invisible in the Plan yet still block the op from ever completing
                $wpId = $wpBySeq[$seqOf($s['waypoint_seq'] ?? null) ?? ''] ?? null;
                if (($text === null && $action === null) || $wpId === null) {
                    continue;
                }
                $links = collect(is_array($s['link_seqs'] ?? null) ? $s['link_seqs'] : [])
                    ->map(fn ($seq) => $wpBySeq[$seqOf($seq) ?? ''] ?? null)->filter()->values()->all();
                $op->steps()->create([
                    'phase' => in_array($s['phase'] ?? '', ['prep', 'run'], true) ? $s['phase'] : 'run',
                    'seq' => (int) ($s['seq'] ?? 0),
                    'text' => $text,
                    'action' => $action,
                    'op_waypoint_id' => $wpId,
                    'assignee_id' => is_string($s['assignee'] ?? null) ? ($assigneeByCallsign[$s['assignee']] ?? null) : null,
                    'resos' => $str($s['resos'] ?? null, 120), 'mods' => $str($s['mods'] ?? null, 120),
                    'qty' => is_numeric($s['qty'] ?? null) ? (min(999, max(0, (int) $s['qty'])) ?: null) : null,
                    'links' => $links ?: null,
                    'notes' => $str($s['notes'] ?? null, 2000),
                ]);
            }

            return $op;
        });

        return redirect()->route('ops.show', $op)->with('success', "Imported “{$op->name}”.");
    }

    /**
     * The operative's shared link. Opening it (GET) only ASKS — it shows whose op this is and that the roster
     * will see your contact details; joining takes the button (POST). A link alone must never join you, or any
     * page could add a signed-in agent to a stranger's op just by getting them to load a URL.
     * Guests sign in / verify / onboard first, then land back here.
     */
    public function join(Request $request, string $token): RedirectResponse|Response
    {
        $user = $request->user();

        // Sign in, verify, and onboard first — carrying the pending join, which resumes once they're done.
        $first = match (true) {
            ! $user => 'login',
            ! $user->hasVerifiedEmail() => 'verification.notice',
            ! $user->callsign => 'onboard',
            default => null,
        };
        if ($first) {
            $request->session()->put('join_token', $token);

            return redirect()->route($first);
        }

        $op = Op::where('join_token', $token)->first();
        if (! $op) {
            return redirect()->route('dashboard')->with('error', 'That op link is invalid or the op has closed.');
        }
        if ($op->isBanned($user)) {
            return redirect()->route('dashboard')->with('error', 'You are banned from this op.');
        }

        if ($op->roleFor($user) !== null) {
            return redirect()->route('ops.show', $op); // already on the roster — nothing to confirm
        }
        if (! $request->isMethod('post')) {
            return Inertia::render('Ops/Join', ['token' => $token, 'name' => $op->name, 'owner' => $op->owner->callsign]);
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
