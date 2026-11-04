<?php

namespace App\Support;

use App\Models\Op;
use Illuminate\Support\Facades\DB;

/**
 * Capture / restore an op's editable plan — the three tables an operative changes during planning:
 * op_waypoints, op_steps, op_key_holdings. Shared by CapturesOpUndo (snapshot before an edit) and
 * OpUndoController (roll back). Rows are stored and re-inserted VERBATIM, ids and timestamps included,
 * so every cross-reference (op_steps.op_waypoint_id, the waypoint-id array in op_steps.links, key
 * holdings) stays valid without any id remapping. Uses the query builder, not models, so JSON columns
 * round-trip as their raw stored string and no model events fire.
 */
class OpPlanSnapshot
{
    /** The op-scoped plan tables, children-before-parents (the safe DELETE order). */
    private const TABLES = ['op_key_holdings', 'op_steps', 'op_waypoints'];

    /** All rows of the three plan tables for the op, as plain arrays. */
    public static function capture(Op $op): array
    {
        $snap = [];
        foreach (self::TABLES as $table) {
            $snap[$table] = DB::table($table)->where('op_id', $op->id)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $snap;
    }

    /**
     * Cheap hash of plan CONTENT for change-detection. Drops the volatile timestamp columns so an edit
     * that merely re-touches updated_at (e.g. a same-order reorder) isn't mistaken for a real change —
     * the stored snapshot still keeps timestamps for a faithful restore; only this compare ignores them.
     */
    public static function signature(array $snap): string
    {
        $content = [];
        foreach ($snap as $table => $rows) {
            $content[$table] = array_map(fn ($r) => array_diff_key($r, ['created_at' => 0, 'updated_at' => 0]), $rows);
        }

        return md5(json_encode($content));
    }

    /** Atomically replace the op's current plan with the snapshot — ids and all. */
    public static function restore(Op $op, array $snap): void
    {
        DB::transaction(function () use ($op, $snap) {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('op_id', $op->id)->delete();
            }
            // re-insert parents before children so FK checks (SQLite enforces them) always resolve
            foreach (array_reverse(self::TABLES) as $table) {
                $rows = array_map(fn ($r) => (array) $r, $snap[$table] ?? []);
                if ($rows !== []) {
                    DB::table($table)->insert($rows);
                }
            }
        });
    }
}
