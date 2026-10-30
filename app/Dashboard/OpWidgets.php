<?php

namespace App\Dashboard;

use App\Models\User;

/**
 * The op-dashboard widget catalog + per-user layout resolution (mirrors RoutePilot's
 * DashboardWidgets). Each widget declares grid sizing; a layout is a list of grid
 * items {i,x,y,w,h} stored per-user on `users.dashboard_layout`, separately for the
 * desktop and mobile grids. A null/empty layout falls back to the defaults below.
 */
class OpWidgets
{
    /** key => {label, minW, minH, w, h defaults}. 12-col grid; h in 40px rows. Glyphs live in the JS WIDGET_ICONS map. */
    private const CATALOG = [
        'map' => ['label' => 'Map', 'minW' => 4, 'minH' => 5, 'w' => 8, 'h' => 8],
        'plan' => ['label' => 'Plan', 'minW' => 3, 'minH' => 5, 'w' => 4, 'h' => 10],
        'roster' => ['label' => 'Roster', 'minW' => 3, 'minH' => 3, 'w' => 6, 'h' => 5],
        'dms' => ['label' => 'Comms', 'minW' => 3, 'minH' => 4, 'w' => 6, 'h' => 7],
        'weather' => ['label' => 'Weather', 'minW' => 4, 'minH' => 4, 'w' => 6, 'h' => 6],
        'notifications' => ['label' => 'Notifications', 'minW' => 3, 'minH' => 3, 'w' => 4, 'h' => 6],
        'progress' => ['label' => 'Progress', 'minW' => 3, 'minH' => 3, 'w' => 4, 'h' => 5],
        'advisories' => ['label' => 'Advisories', 'minW' => 3, 'minH' => 3, 'w' => 4, 'h' => 6],
        'activity' => ['label' => 'Activity log', 'minW' => 3, 'minH' => 4, 'w' => 4, 'h' => 7, 'operatorOnly' => true],
        'notes' => ['label' => 'Notes', 'minW' => 3, 'minH' => 3, 'w' => 4, 'h' => 5],
        'ai' => ['label' => 'AI Concierge', 'minW' => 3, 'minH' => 4, 'w' => 4, 'h' => 8],
        'cycle' => ['label' => 'Cycle', 'minW' => 3, 'minH' => 3, 'w' => 4, 'h' => 5],
    ];

    /** Default desktop grid (12 cols) — the full war-room, mirroring the "The Pearl" reference layout:
     *  Map + Plan up top, then Roster/Comms + Notes/AI, then Weather/Cycle/Advisories, Progress + alerts. */
    private const DEFAULTS = [
        ['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 7, 'h' => 14],
        ['i' => 'plan', 'x' => 7, 'y' => 0, 'w' => 5, 'h' => 14],
        ['i' => 'roster', 'x' => 7, 'y' => 14, 'w' => 5, 'h' => 7],
        ['i' => 'dms', 'x' => 7, 'y' => 21, 'w' => 5, 'h' => 7],
        ['i' => 'notes', 'x' => 0, 'y' => 14, 'w' => 7, 'h' => 7],
        ['i' => 'ai', 'x' => 0, 'y' => 21, 'w' => 7, 'h' => 7],
        ['i' => 'weather', 'x' => 0, 'y' => 28, 'w' => 7, 'h' => 11],
        ['i' => 'cycle', 'x' => 7, 'y' => 28, 'w' => 5, 'h' => 5],
        ['i' => 'advisories', 'x' => 7, 'y' => 33, 'w' => 5, 'h' => 8],
        ['i' => 'progress', 'x' => 0, 'y' => 39, 'w' => 7, 'h' => 8],
        ['i' => 'notifications', 'x' => 7, 'y' => 41, 'w' => 5, 'h' => 6],
    ];

    /** Default mobile grid — only Map + Plan; every other widget opens as a full page. */
    private const DEFAULTS_MOBILE = [
        ['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 7],
        ['i' => 'plan', 'x' => 0, 'y' => 7, 'w' => 12, 'h' => 10],
    ];

    /** Retired widgets fold into their replacement so a saved layout keeps a planning panel. */
    private const ALIASES = ['directives' => 'plan', 'keys' => 'plan'];

    /** The catalog the viewer may use — operator-only widgets (e.g. the activity log) are hidden from agents. */
    public static function meta(bool $isOperative = true): array
    {
        return array_filter(self::CATALOG, fn ($w) => $isOperative || empty($w['operatorOnly']));
    }

    /**
     * The user's desktop + mobile layouts for an op (sanitized to the widgets they may use), or the per-mode defaults.
     *
     * @return array{desktop: list<array<string,int|string>>, mobile: list<array<string,int|string>>}
     */
    public static function layoutsFor(User $user, ?string $opKey = null, bool $isOperative = true): array
    {
        // Layouts are per-op, keyed by the op's public_id (the id the client also sends when saving):
        // dashboard_layout = ['ops' => ['<public_id>' => ['desktop' => [...], 'mobile' => [...]]]].
        // A new/uncustomized op starts from DEFAULTS, so each op's dashboard is independent.
        $perOp = $user->dashboard_layout['ops'][(string) $opKey] ?? null;

        return [
            'desktop' => self::sanitize($perOp['desktop'] ?? null, $isOperative) ?: self::DEFAULTS,
            'mobile' => self::sanitize($perOp['mobile'] ?? null, $isOperative) ?: self::DEFAULTS_MOBILE,
        ];
    }

    /** Keep each widget the viewer may use (once, retired keys folded in) and coerce the grid fields. */
    private static function sanitize(mixed $layout, bool $isOperative): ?array
    {
        if (! is_array($layout)) {
            return null;
        }
        $allowed = self::meta($isOperative);
        $out = [];
        foreach ($layout as $item) {
            $key = is_string($item['i'] ?? null) ? (self::ALIASES[$item['i']] ?? $item['i']) : null;
            if ($key === null || ! isset($allowed[$key]) || isset($out[$key])) {
                continue;
            }
            $h = (int) ($item['h'] ?? $allowed[$key]['h']);
            $out[$key] = [
                'i' => $key,
                'x' => (int) ($item['x'] ?? 0),
                'y' => (int) ($item['y'] ?? 0),
                'w' => (int) ($item['w'] ?? $allowed[$key]['w']),
                'h' => $h,
                'collapsed' => (bool) ($item['collapsed'] ?? false),
                'fullH' => (int) ($item['fullH'] ?? $h),
            ];
        }

        return array_values($out) ?: null;
    }
}
