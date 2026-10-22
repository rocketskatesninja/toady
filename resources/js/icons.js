// Widget-type icons (Lucide), keyed by widget id. MUST cover every key in app/Dashboard/OpWidgets.php CATALOG.
import { Map as MapIcon, KeyRound, Users, MessagesSquare, CloudSun, Bell, UserPlus, CheckCircle2, MessageSquare, ClipboardCheck, Hand, Rocket, Gauge, AtSign, Bug, TriangleAlert, ScrollText, NotebookPen, Bot, TimerReset, ClipboardList } from 'lucide-vue-next';

export const WIDGET_ICONS = {
    map: MapIcon,
    plan: ClipboardList,
    roster: Users,
    dms: MessagesSquare,
    weather: CloudSun,
    notifications: Bell,
    progress: Gauge,
    advisories: TriangleAlert,
    activity: ScrollText,
    notes: NotebookPen,
    ai: Bot,
    cycle: TimerReset,
};

// Op full-page views (hamburger → ?view=…): the single source for the menu list + the focus pages.
// `scroll: true` = a list view that scrolls; the rest (map/dms/notifications/weather) manage their own height.
export const OP_VIEWS = [
    { key: 'map', label: 'Map' },
    { key: 'plan', label: 'Plan', scroll: true },
    { key: 'roster', label: 'Roster', scroll: true },
    { key: 'dms', label: 'Comms' },
    { key: 'weather', label: 'Weather' },
    { key: 'notifications', label: 'Notifications' },
    { key: 'progress', label: 'Progress', scroll: true },
    { key: 'advisories', label: 'Advisories', scroll: true },
    { key: 'notes', label: 'Notes' },
    { key: 'cycle', label: 'Cycle' },
    { key: 'ai', label: 'AI Concierge' },
    { key: 'activity', label: 'Activity log', scroll: true, op: true }, // operator-only
];

// Notification-type → icon (matches the `type` set the server's Notifier emits). Falls back to Bell.
export const NOTIF_ICONS = {
    dm: MessageSquare,      // a message bubble
    mention: AtSign,        // @mentioned in op chat
    task: ClipboardCheck,   // assigned to you
    turn: Hand,             // your turn to act
    go: Rocket,             // op is live
    keys: KeyRound,         // keys fully farmed
    done: CheckCircle2,     // a directive was completed
    join: UserPlus,         // an agent joined
    report: Bug,            // a problem report (owner)
};
export const NOTIF_FALLBACK = Bell;
