<?php

namespace App\Console\Commands;

use App\Models\MasterPortal;
use App\Models\Op;
use App\Models\OpParticipant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed a fully-populated demo op (agents, waypoints, directives, presence, chat) owned by
 * the catalog owner, so they can explore a live op. Idempotent: wipes the prior demo first.
 */
class DemoSeed extends Command
{
    protected $signature = 'toady:demo {--name=Brunswick Multilayer (DEMO)}';

    protected $description = 'Seed a demo op for the owner to test with.';

    private const DEMO_DOMAIN = '@demo.toady.local';
    private const CENTER = [31.15, -81.4915];

    public function handle(): int
    {
        $owner = User::where('is_owner', true)->orderBy('id')->first()
            ?? User::whereNotNull('callsign')->orderBy('id')->first();

        if (! $owner) {
            $this->error('No owner/user to attach the op to — sign in first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($owner) {
            // Reuse the existing demo op row (stable ID / URL across re-seeds) — just refresh its contents.
            $op = Op::firstOrNew(['name' => $this->option('name')]);
            $op->owner_id = $owner->id;
            $op->description = 'Three-layer fan field over downtown Brunswick. Anchors hold; spine throws inbound.';
            $op->type = 'any_order';
            $op->status = 'active';
            if (! $op->exists) {
                $op->join_token = Op::freshToken();
            }
            $op->save();

            // clear its prior contents + the old demo agents, then rebuild from scratch
            $op->keyHoldings()->delete();
            $op->steps()->delete();
            $op->waypoints()->delete();
            $op->participants()->delete();
            $op->presence()->delete();
            $op->messages()->delete();
            User::where('email', 'like', '%'.self::DEMO_DOMAIN)->get()->each->delete();

            // demo agents (OAuth-less; populate the op)
            $agents = [];
            foreach (['Vector', 'Relay', 'Beacon', 'Shade'] as $cs) {
                $agents[] = User::create([
                    'callsign' => $cs, 'email' => strtolower($cs).self::DEMO_DOMAIN, 'faction' => 'ENL',
                ]);
            }

            $op->participants()->create(['user_id' => $owner->id, 'role' => OpParticipant::ROLE_OPERATIVE]);
            foreach ($agents as $a) {
                $op->participants()->create(['user_id' => $a->id, 'role' => OpParticipant::ROLE_AGENT]);
            }

            // 6 nearest real portals → waypoints (snapshot intel)
            [$lat, $lng] = self::CENTER;
            $portals = MasterPortal::whereNotNull('title')
                ->orderByRaw('POW(lat - ?, 2) + POW(lng - ?, 2)', [$lat, $lng])
                ->limit(6)->get();
            $roles = ['anchor', 'spine', 'spine', 'target', 'spine', 'anchor'];
            $wps = [];
            foreach ($portals as $i => $p) {
                $wps[] = $op->waypoints()->create([
                    'seq' => $i + 1, 'role' => $roles[$i], 'title' => $p->title, 'lat' => $p->lat, 'lng' => $p->lng,
                    'gate_pin' => $p->gate_pin, 'access_notes' => $p->access_notes, 'parking' => $p->parking,
                    'hours' => $p->hours, 'hazards' => $p->hazards,
                ]);
            }
            $anchors = [$wps[0]->title, $wps[5]->title];

            // key locker: anchors receive the inbound links → set the plan need + seed agent holdings
            // (north anchor short by 2; south anchor fully keyed → demos the shortfall view)
            $wps[0]->update(['keys_needed' => 6]);
            $wps[5]->update(['keys_needed' => 4]);
            $op->keyHoldings()->createMany([
                ['op_waypoint_id' => $wps[0]->id, 'user_id' => $agents[0]->id, 'qty' => 3],
                ['op_waypoint_id' => $wps[0]->id, 'user_id' => $agents[1]->id, 'qty' => 1],
                ['op_waypoint_id' => $wps[5]->id, 'user_id' => $agents[2]->id, 'qty' => 4],
            ]);

            // a generic (unplaced) staging location — also demos a card with no catalog portal
            $staging = $op->waypoints()->create(['seq' => 7, 'role' => 'waypoint', 'title' => 'Staging — north lot', 'lat' => null, 'lng' => null]);

            // directives (every directive belongs to a location card)
            $prep = [
                ['text' => 'Top off all resonators', 'action' => 'recharge', 'assignee_id' => $agents[0]->id, 'done' => true],
                ['text' => 'Confirm key counts', 'action' => 'note', 'assignee_id' => $agents[1]->id, 'done' => true],
                ['text' => 'Rally up before we move', 'action' => 'move', 'notes' => 'Free street parking after 6pm.'],
            ];
            foreach ($prep as $i => $s) {
                $op->steps()->create(array_merge(['phase' => 'prep', 'seq' => $i + 1, 'op_waypoint_id' => $staging->id,
                    'done_by' => ($s['done'] ?? false) ? ($s['assignee_id'] ?? $owner->id) : null,
                    'done_at' => ($s['done'] ?? false) ? now()->subMinutes(15) : null], $s));
            }
            $run = [
                ['text' => 'Deploy + fully load north anchor', 'action' => 'deploy', 'op_waypoint_id' => $wps[0]->id, 'assignee_id' => $agents[0]->id, 'resos' => '8×L8', 'done' => true],
                ['text' => 'Hack for keys ×4', 'action' => 'hack', 'op_waypoint_id' => $wps[1]->id, 'assignee_id' => $agents[1]->id, 'notes' => 'Heat-sink if cooldown bites.'],
                ['text' => 'Deploy resonators', 'action' => 'deploy', 'op_waypoint_id' => $wps[2]->id, 'assignee_id' => $agents[3]->id, 'resos' => '8×L7'],
                ['text' => 'Throw links to both anchors', 'action' => 'link', 'op_waypoint_id' => $wps[3]->id, 'assignee_id' => $agents[2]->id, 'links' => $anchors],
                ['text' => 'Confirm all three fields are up', 'action' => 'note', 'op_waypoint_id' => $wps[3]->id, 'assignee_id' => $owner->id],
            ];
            foreach ($run as $i => $s) {
                $op->steps()->create(array_merge(['phase' => 'run', 'seq' => $i + 1,
                    'done_by' => ($s['done'] ?? false) ? ($s['assignee_id'] ?? null) : null,
                    'done_at' => ($s['done'] ?? false) ? now()->subMinutes(4) : null], $s));
            }

            // live presence (fresh; stales after 2 min) + chatter
            foreach ([[$agents[0], $wps[0], 12], [$agents[1], $wps[1], 25], [$agents[2], $wps[3], 18]] as [$a, $wp, $acc]) {
                $op->presence()->create(['user_id' => $a->id, 'sharing' => true, 'lat' => $wp->lat, 'lng' => $wp->lng, 'accuracy' => $acc, 'last_seen' => now()]);
            }
            foreach ([[$owner->id, 'Anchors first, then spine. Call your keys.'], [$agents[0]->id, 'North anchor fully loaded ✅'], [$agents[1]->id, 'On the farm portal, keys incoming.']] as [$uid, $body]) {
                $op->messages()->create(['user_id' => $uid, 'body' => $body]);
            }

            $this->info("Demo op #{$op->id} seeded for {$owner->callsign}: ".$op->waypoints()->count().' waypoints, '.$op->steps()->count().' steps, 3 pins, 3 messages.');
        });

        return self::SUCCESS;
    }
}
