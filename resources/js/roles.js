import { Anchor, Spline, Target, Circle } from 'lucide-vue-next';

// A portal's field role → a representative icon + its colour, shared by the Plan panel and the map
// so the badge sets can't drift. The pale rose/amber get a darker daylight: variant so
// they stay legible on the white daylight surface (same reason the old text badges did).
const ROLE_ICON = { anchor: Anchor, spine: Spline, target: Target, waypoint: Circle };
const ROLE_COLOR = {
    anchor: 'text-rose-300 daylight:text-rose-700',
    spine: 'text-accent',
    target: 'text-amber-300 daylight:text-amber-700',
    waypoint: 'text-ink-dim',
};

export const roleIcon = (role) => ROLE_ICON[role] || ROLE_ICON.waypoint;
export const roleColor = (role) => ROLE_COLOR[role] || ROLE_COLOR.waypoint;
