// Pure clock-math for the Ingress cycle timer. NO score/game data is fetched — the whole schedule is
// extrapolated from a single admin-set anchor (a checkpoint that begins a cycle) + a fixed cadence.
// Shared by the CycleWidget and the admin preview so the two can't drift. `cfg` = page.props.cycle.
export function computeCycle(cfg, nowMs) {
    if (! cfg || ! cfg.anchor) return null;
    const anchor = Date.parse(cfg.anchor);
    const intervalMs = (Number(cfg.interval_hours) || 5) * 3600000;
    const M = Math.max(1, Math.round(Number(cfg.checkpoints_per_cycle) || 35));
    if (! Number.isFinite(anchor) || intervalMs <= 0) return null;

    const elapsed = nowMs - anchor;
    if (elapsed < 0) return { pending: true, toStart: anchor - nowMs }; // anchor set in the future

    const totalCp = Math.floor(elapsed / intervalMs);          // checkpoints elapsed since the anchor
    const cpInCycle = (totalCp % M) + 1;                       // current checkpoint, 1..M
    const cyclesElapsed = Math.floor(totalCp / M);             // full cycles since the anchor
    const nextCpAt = anchor + (totalCp + 1) * intervalMs;      // upcoming checkpoint instant
    const cycleEndsAt = anchor + (cyclesElapsed + 1) * M * intervalMs;

    // real designation "YYYY.NN" — the anchor's cycle number + cycles elapsed (simple: year stays fixed,
    // the admin re-anchors at the year boundary). Falls back to a relative count when no label is set.
    const year = Number(cfg.year) || null;
    const baseNo = Number(cfg.number) || null;
    const label = (year && baseNo)
        ? `${year}.${String(baseNo + cyclesElapsed).padStart(2, '0')}`
        : `#${cyclesElapsed + 1}`;

    return {
        pending: false, M, cpInCycle, label,
        nextCpAt, toNextCp: nextCpAt - nowMs,
        cycleEndsAt, toCycleEnd: cycleEndsAt - nowMs,
        pct: Math.round(((cpInCycle - 1) / M) * 100),
    };
}

const two = (n) => String(n).padStart(2, '0');

/** ms → "Dd HH:MM:SS" (drops the day part under 24h). */
export function fmtDur(ms) {
    let s = Math.max(0, Math.floor(ms / 1000));
    const d = Math.floor(s / 86400); s -= d * 86400;
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60); s -= m * 60;
    return (d > 0 ? d + 'd ' : '') + two(h) + ':' + two(m) + ':' + two(s);
}
