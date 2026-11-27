<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['op_id', 'seq', 'role', 'guid', 'keys_needed', 'title', 'lat', 'lng', 'image', 'gate_pin', 'access_notes', 'parking', 'hours', 'hazards'])]
class OpWaypoint extends Model
{
    /** The field-intel a location carries (catalog portals have the same five) → each one's max length. One
     *  list, so the rules, the payloads, export/import and the AI snapshot can't drift apart. */
    public const INTEL = ['gate_pin' => 64, 'parking' => 255, 'hours' => 255, 'access_notes' => 2000, 'hazards' => 2000];

    /** Validation rules for the intel fields; $partial = only validate the ones a request sends. */
    public static function intelRules(bool $partial = false): array
    {
        return array_map(fn (int $max) => [...($partial ? ['sometimes'] : []), 'nullable', 'string', "max:{$max}"], self::INTEL);
    }

    public const ROLES = ['anchor', 'spine', 'target', 'waypoint'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
