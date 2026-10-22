<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendCampaign;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUserActionRequest;
use App\Http\Requests\SendCampaignRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('callsign', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->withCount(['ownedOps', 'participations'])
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'callsign' => $u->callsign,
                'faction' => $u->faction,
                'email' => $u->email,
                'avatar' => $u->avatarUrl(),
                'is_owner' => (bool) $u->is_owner,
                'is_admin' => (bool) $u->is_admin,
                'trusted' => (bool) $u->trusted, // trusted catalog contributor (single submit auto-verifies)
                'suspended' => $u->suspended_at !== null,
                'opted_out' => (bool) $u->email_opt_out,
                'ops' => $u->owned_ops_count,
                'joined_ops' => $u->participations_count,
                'joined' => $u->created_at?->toDateString(),
                'is_self' => $u->id === $request->user()->id,
            ]);

        $audit = AuditLog::with('actor:id,callsign')->latest('id')->limit(15)->get()
            ->map(fn (AuditLog $a) => [
                'id' => $a->id,
                'who' => $a->actor?->callsign ?? $a->actor_label ?? 'system',
                'action' => $a->action,
                'summary' => $a->summary,
                'at' => $a->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'q' => $q,
            'audit' => $audit,
            'mail' => ['header' => $request->user()->mail_header, 'signature' => $request->user()->mail_signature],
            'optedInCount' => User::whereNotNull('email')->where('email_opt_out', false)->count(),
        ]);
    }

    /** Suspend / re-enable / delete several accounts at once. Never touches you or the owner. */
    public function bulk(BulkUserActionRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $action = $request->validated('action');
        abort_if(in_array($action, ['trust', 'untrust'], true) && ! $actor->is_owner, 403); // only the owner blesses contributors

        $users = User::whereKey($request->validated('ids'))
            ->whereKeyNot($actor->id)   // never act on yourself
            ->where('is_owner', false)  // the owner is untouchable here
            ->get();

        $count = 0;
        foreach ($users as $u) {
            if ($action === 'delete') {
                $u->delete();
            } elseif ($action === 'suspend' && ! $u->suspended_at) {
                $u->suspended_at = now();
                $u->save();
            } elseif ($action === 'unsuspend' && $u->suspended_at) {
                $u->suspended_at = null;
                $u->save();
            } elseif ($action === 'trust' && ! $u->trusted) {
                $u->forceFill(['trusted' => true])->save(); // not mass-assignable
            } elseif ($action === 'untrust' && $u->trusted) {
                $u->forceFill(['trusted' => false])->save();
            } else {
                continue; // already in the requested state
            }
            $this->log($actor, $action, $u->callsign ?: $u->email);
            $count++;
        }

        $verb = ['suspend' => 'Suspended', 'unsuspend' => 'Re-enabled', 'delete' => 'Deleted', 'trust' => 'Trusted', 'untrust' => 'Untrusted'][$action];

        return back()->with('success', "{$verb} {$count} account".($count === 1 ? '' : 's').'.');
    }

    /** Owner-only (gated in the request): queue a broadcast email to selected — or all opted-in — users. */
    public function email(SendCampaignRequest $request, SendCampaign $action): RedirectResponse
    {
        $data = $request->validated();
        $sender = $request->user();

        $campaign = $action->handle($data, $sender);
        $sender->update(['mail_header' => $data['header'] ?? null, 'mail_signature' => $data['signature'] ?? null]);
        $this->log($sender, 'email', $campaign->recipient_count.' recipient(s) · '.$campaign->subject);

        return back()->with('success', "Queued to {$campaign->recipient_count} recipient(s) — they'll send out shortly.");
    }

    private function log(User $actor, string $action, string $summary): void
    {
        AuditLog::create([
            'actor_id' => $actor->id,
            'actor_label' => $actor->callsign,
            'action' => $action,
            'summary' => $summary,
        ]);
    }
}
