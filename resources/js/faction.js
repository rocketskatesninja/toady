// Fixed faction colors — ENL green / RES blue — independent of the viewer's own accent theme,
// so an agent's label always reads as their real faction (not the viewer's).
export function factionText(f) {
    return f === 'ENL' ? 'text-enl' : f === 'RES' ? 'text-res' : 'text-ink-dim';
}
export function factionChip(f) {
    return f === 'ENL' ? 'text-enl border-enl/40' : f === 'RES' ? 'text-res border-res/40' : 'text-ink-dim border-line';
}
// the stored role 'operative' is shown to users as "Operator" (vs "Agent")
export function roleLabel(role) {
    return role === 'operative' ? 'Operator' : 'Agent';
}
