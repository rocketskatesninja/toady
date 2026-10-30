<script setup>
import { computed, ref, watch, watchEffect, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Toaster from '@/Components/Toaster.vue';
import PushToggle from '@/Components/PushToggle.vue';
import ReportProblem from '@/Components/ReportProblem.vue';
import BrandMark from '@/Components/BrandMark.vue';
import { useTheme } from '@/useTheme';
import { useFatFinger } from '@/useFatFinger';
import { useDeviceMode } from '@/useDeviceMode';
import { setNotifyVibrate, useNotify } from '@/useNotify';
import { WIDGET_ICONS, OP_VIEWS } from '@/icons';
import { ui } from '@/ui';
import { queryParam } from '@/url';
import { lsJSON, lsSet, lsRemove } from '@/ls';
import { LayoutGrid, Library, ShieldCheck, BookOpen, Compass, CircleUser, LayoutDashboard, Moon, Sun, ZoomIn, ZoomOut, LogOut, Inbox, Bug, Images, LayoutList, Grid2x2 } from 'lucide-vue-next';

const page = usePage();
const user = computed(() => page.props.auth.user);
// Server flash (success/error) funnels into the shared toast queue — one notification system, one stack.
const { notify } = useNotify();
let lastFlashSig = null;
watch(() => page.props.flash, (raw) => {
    const f = raw ?? {};
    const sig = `${f.success ?? ''}|${f.error ?? ''}`;
    if (sig === '|' || sig === lastFlashSig) return;
    lastFlashSig = sig;
    if (f.success) notify('', f.success, 'success');
    if (f.error) notify('', f.error, 'error');
}, { immediate: true, deep: true });

// RES agents get a blue site highlight — keep <html class="res"> in sync (also set server-side for no-flash).
watchEffect(() => { document.documentElement.classList.toggle('res', user.value?.faction === 'RES'); });
// keep the in-app banner's phone-buzz in sync with the user's notification preference
watchEffect(() => setNotifyVibrate(user.value?.notify_prefs?.vibrate));

// ── where you are ─────────────────────────────────────────────────────────────────────────────────────
const url = computed(() => ({ path: page.url.split('?')[0], view: queryParam(page.url, 'view') }));
const currentPath = computed(() => url.value.path);
const onOpsBoard = computed(() => currentPath.value === '/dashboard');
const { mode } = useDeviceMode(); // 'mobile' | 'desktop'

// The op the menu is about: the one you're on, or the last one you opened, so its links persist on other
// pages (AppLayout re-mounts each navigation, so it's kept in localStorage).
const LAST_OP = 'toady:lastOp';
const liveOp = computed(() => page.props.op ?? null);
const rememberedOp = ref(null);
onMounted(() => {
    if (liveOp.value) {
        rememberedOp.value = { id: liveOp.value.id, name: liveOp.value.name };
        lsSet(LAST_OP, JSON.stringify(rememberedOp.value));
        return;
    }
    const remembered = lsJSON(LAST_OP);
    // on the ops board, forget a remembered op that's since been closed — or the menu would link to a 404
    const ops = page.props.ops;
    if (remembered && Array.isArray(ops) && !ops.some((o) => o.id === remembered.id)) { lsRemove(LAST_OP); return; }
    rememberedOp.value = remembered;
});
const currentOp = computed(() => liveOp.value ?? rememberedOp.value);
const opPath = computed(() => (currentOp.value ? `/ops/${currentOp.value.id}` : null));
const onOpPage = computed(() => currentPath.value === opPath.value);  // the op's dashboard or one of its pages
const onOpDashboard = computed(() => onOpPage.value && !url.value.view);

// header bell → the op's notifications: scroll to the widget if it's on the current dashboard, else open the page
function goToNotifications() {
    const el = onOpDashboard.value ? document.getElementById('w-notifications') : null;
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    router.visit(currentOp.value ? `${opPath.value}?view=notifications` : '/dashboard');
}
const { theme, toggle: toggleTheme } = useTheme();
const { on: fatFinger, toggle: toggleFatFinger } = useFatFinger();
const reportOpen = ref(false);

// Header bell: unread count, seeded from the shared prop then polled.
const unread = ref(page.props.unreadNotifications ?? 0);
let unreadTimer = null;
let announcing = false; // our own "changed" broadcast — the listener below mustn't re-poll for it
async function pollUnread() {
    try {
        const { data } = await window.axios.get('/notifications/feed');
        const arrived = data.unread > unread.value; // new notification(s) since the last check
        unread.value = data.unread;
        // tell any open panel (notifications widget, DM panel) to reload its list — keeps them current
        // without a page reload even when their own poll was throttled in a backgrounded tab
        if (arrived) {
            announcing = true;
            window.dispatchEvent(new CustomEvent('toady:notifications-changed'));
            announcing = false;
        }
    } catch (e) { /* keep last */ }
}
const onNotificationsChanged = () => { if (!announcing) pollUnread(); }; // mark-read / clear elsewhere → badge at once
onMounted(() => {
    pollUnread();
    unreadTimer = setInterval(pollUnread, 25000);
    window.addEventListener('toady:notifications-changed', onNotificationsChanged);
});
onBeforeUnmount(() => {
    clearInterval(unreadTimer);
    window.removeEventListener('toady:notifications-changed', onNotificationsChanged);
});

// ── the menu ──────────────────────────────────────────────────────────────────────────────────────────
// One set of entries drawn two ways: the labeled menu (icon + word tiles) and the compact menu (icon bars and
// rows). The style is chosen per mode and remembered on this device (default: labeled on mobile, compact on
// desktop). The ops board always uses the compact menu, with the site links as its rows, no op section, and
// no style toggle.
const menuOpen = ref(false);
const MENU_STYLE_KEY = 'toady-menu-style';
const menuStyles = ref({ mobile: 'labeled', desktop: 'compact', ...lsJSON(MENU_STYLE_KEY, {}) });
const labeledMenu = computed(() => !onOpsBoard.value && menuStyles.value[mode.value] === 'labeled');
function toggleMenuStyle() {
    menuStyles.value = { ...menuStyles.value, [mode.value]: labeledMenu.value ? 'compact' : 'labeled' };
    lsSet(MENU_STYLE_KEY, JSON.stringify(menuStyles.value));
}

// the app's sections this user can reach, minus the page you're on — tiles show `label`; rows and the icon
// bar's tooltips show the fuller `title`
const goTo = computed(() => [
    { href: '/dashboard', icon: LayoutGrid, label: 'Ops', title: 'Ops' },
    user.value?.is_owner && { href: '/catalog', icon: Library, label: 'Catalog', title: 'Catalog' },
    user.value?.is_admin && { href: '/admin/users', icon: ShieldCheck, label: 'Users', title: 'Admin · users' },
    user.value?.is_admin && { href: '/admin/reports', icon: Bug, label: 'Reports', title: 'Problem reports' },
    user.value?.is_admin && { href: '/admin/showcase', icon: Images, label: 'Showcase', title: 'Showcase gallery' },
    { href: '/reference', icon: BookOpen, label: 'Reference', title: 'Reference' },
    { href: '/guide', icon: Compass, label: 'Guide', title: 'Guide · run an op' },
].filter((l) => l && l.href !== currentPath.value));

// The op's links: its dashboard (unless you're on it) and a full page for each widget that isn't on this
// mode's dashboard grid. The op page publishes both grids (localStorage, plus a 'toady:grids' event while it's
// open) so these never point at a widget that's already on the grid. Notifications is left out — the header's
// inbox icon opens it, and one less row keeps the compact menu on screen.
const grids = ref({ mobile: ['map', 'plan'], desktop: ['map', 'plan', 'roster', 'dms', 'notes'] }); // server defaults
function readGrids() {
    for (const m of ['mobile', 'desktop']) { const g = lsJSON(`toady:${m}Grid`); if (Array.isArray(g)) grids.value[m] = g; }
}
const onGrids = (e) => { for (const m of ['mobile', 'desktop']) if (Array.isArray(e.detail?.[m])) grids.value[m] = e.detail[m]; };
onMounted(() => { readGrids(); window.addEventListener('toady:grids', onGrids); });
onBeforeUnmount(() => window.removeEventListener('toady:grids', onGrids));
const showOp = computed(() => !!currentOp.value && !onOpsBoard.value);
const opLinks = computed(() => (!showOp.value ? [] : [
    !onOpDashboard.value && { href: opPath.value, icon: LayoutDashboard, label: 'Op dashboard' },
    ...OP_VIEWS
        .filter((v) => v.key !== 'notifications' && !grids.value[mode.value].includes(v.key) && !(onOpPage.value && v.key === url.value.view) && (!v.op || currentOp.value.is_operative))
        .map((v) => ({ href: `${opPath.value}?view=${v.key}`, icon: WIDGET_ICONS[v.key], label: v.label })),
].filter(Boolean)));

// preferences — tiles show `label`, the icon bar shows `title` (the ops board has no menu-style toggle)
const prefs = computed(() => [
    { id: 'theme', run: toggleTheme, icon: theme.value === 'daylight' ? Moon : Sun,
      label: theme.value === 'daylight' ? 'Night mode' : 'Daylight', title: theme.value === 'daylight' ? 'Night mode' : 'Daylight mode' },
    { id: 'size', run: toggleFatFinger, icon: fatFinger.value ? ZoomOut : ZoomIn,
      label: fatFinger.value ? 'Compact view' : 'Big view', title: `${fatFinger.value ? 'Compact' : 'Big'} view (${mode.value} mode)` },
    !onOpsBoard.value && { id: 'menu', run: toggleMenuStyle, icon: labeledMenu.value ? Grid2x2 : LayoutList,
      label: labeledMenu.value ? 'Compact menu' : 'Labeled menu', title: `Use the ${labeledMenu.value ? 'compact' : 'labeled'} menu in ${mode.value} mode` },
].filter(Boolean));
function report() { reportOpen.value = true; menuOpen.value = false; }

watch(() => page.url, () => (menuOpen.value = false));
// Close on any tap/click outside the menu (its button + dropdown both live inside menuRoot). A document
// listener — not a fixed backdrop — because the header's backdrop-blur makes `position: fixed` children cover
// only the header bar, not the whole screen, so an inset-0 overlay never catches taps on the page body.
const menuRoot = ref(null);
const onDocPointerDown = (e) => { if (menuRoot.value && !menuRoot.value.contains(e.target)) menuOpen.value = false; };
watch(menuOpen, (open) => {
    if (open) { readGrids(); document.addEventListener('pointerdown', onDocPointerDown); }
    else { document.removeEventListener('pointerdown', onDocPointerDown); }
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocPointerDown));

function logout() {
    if (!confirm('Log out?')) return;
    menuOpen.value = false;
    document.documentElement.classList.remove('res');
    router.post('/logout');
}

const navBase = 'menu-stagger absolute left-0 -mt-3 z-40 max-h-[calc(100dvh-4rem)] overflow-y-auto bg-surface border border-line rounded-lg shadow-xl font-mono';
</script>

<template>
    <div class="min-h-screen text-ink font-sans">
        <header class="sticky top-0 z-30 border-b border-emerald-500/20 bg-surface/90 backdrop-blur">
            <div class="mx-auto max-w-7xl px-1 h-14 flex items-center gap-3">
                <!-- brand doubles as the main menu -->
                <div ref="menuRoot" class="relative shrink-0">
                    <button @click="menuOpen = !menuOpen"
                        class="flex items-center gap-1.5 font-mono text-accent glow tracking-widest text-lg hover:opacity-90" title="menu">
                        <BrandMark size="1.05em" /><span class="hidden sm:inline">toady</span>
                    </button>
                    <Transition name="menu-pop">
                        <!-- labeled menu: icon + word tiles in sections -->
                        <nav v-if="menuOpen && labeledMenu" :class="[navBase, 'op-scroll w-76 px-2 pb-2']">
                            <section v-if="goTo.length">
                                <h4 :class="ui.sec" class="px-1 pt-2.5">Go to</h4>
                                <div class="grid grid-cols-2 gap-1">
                                    <Link v-for="l in goTo" :key="l.href" :href="l.href" :class="ui.menuTile"><component :is="l.icon" :size="16" class="shrink-0" /><span class="truncate">{{ l.label }}</span></Link>
                                </div>
                            </section>
                            <section v-if="showOp">
                                <h4 :class="ui.sec" class="px-1 pt-2.5"><span class="min-w-0 truncate">{{ currentOp.name }}</span></h4>
                                <!-- the op's tools, below sm where the top bar hides them -->
                                <div class="sm:hidden"><slot name="op-menu" :close="() => (menuOpen = false)" :compact="false" /></div>
                                <div class="grid grid-cols-2 gap-1">
                                    <Link v-for="l in opLinks" :key="l.href" :href="l.href" :class="ui.menuTile"><component :is="l.icon" :size="16" class="shrink-0" /><span class="truncate">{{ l.label }}</span></Link>
                                </div>
                            </section>
                            <section>
                                <h4 :class="ui.sec" class="px-1 pt-2.5">Settings</h4>
                                <div class="grid grid-cols-2 gap-1">
                                    <button v-for="p in prefs" :key="p.id" @click="p.run" :class="ui.menuTile" :title="p.title"><component :is="p.icon" :size="16" class="shrink-0" /><span class="truncate">{{ p.label }}</span></button>
                                    <PushToggle tile />
                                    <button @click="report" :class="ui.menuTile" title="Report a problem"><Bug :size="16" class="shrink-0" /><span class="truncate">Report issue</span></button>
                                    <Link v-if="currentPath !== '/profile'" href="/profile" :class="ui.menuTile" :title="`Profile · ${user?.callsign || ''}`"><CircleUser :size="16" class="shrink-0" /><span class="truncate">Profile</span></Link>
                                    <button @click="logout" :class="ui.menuTileDanger"><LogOut :size="16" class="shrink-0" /><span class="truncate">Log out</span></button>
                                </div>
                            </section>
                        </nav>

                        <!-- compact menu: icon bar of sections · the op's links as rows · settings + profile icon bar · logout.
                             On the ops board the site links are the rows instead of the icon bar. No scrollbars: w-64
                             fits a 6-icon bar (6 × w-9 + gaps + px-2), and a menu taller than the screen scrolls bare. -->
                        <nav v-else-if="menuOpen" :class="[navBase, 'no-scrollbar overflow-x-hidden w-64 py-1 text-sm']">
                            <div v-if="onOpsBoard">
                                <Link v-for="l in goTo" :key="l.href" :href="l.href" :class="ui.menuRow"><component :is="l.icon" :size="16" class="shrink-0" /> {{ l.title }}</Link>
                            </div>
                            <div v-else class="flex items-center gap-0.5 px-2 py-1.5">
                                <Link v-for="l in goTo" :key="l.href" :href="l.href" :class="ui.barIcon" :title="l.title"><component :is="l.icon" :size="18" /></Link>
                            </div>
                            <div v-if="showOp" class="border-t border-line pt-1">
                                <div class="px-3 pb-0.5 text-[10px] uppercase tracking-wide text-ink-faint truncate">{{ currentOp.name }}</div>
                                <div class="sm:hidden px-2 py-1"><slot name="op-menu" :close="() => (menuOpen = false)" :compact="true" /></div>
                                <Link v-for="l in opLinks" :key="l.href" :href="l.href" :class="ui.menuRow"><component :is="l.icon" :size="16" class="shrink-0" /> {{ l.label }}</Link>
                            </div>
                            <div class="flex items-center gap-0.5 px-2 py-1.5 border-t border-line">
                                <button v-for="p in prefs" :key="p.id" @click="p.run" :class="ui.barIcon" :title="p.title"><component :is="p.icon" :size="18" /></button>
                                <PushToggle />
                                <button @click="report" :class="ui.barIcon" title="Report a problem"><Bug :size="18" /></button>
                                <Link v-if="currentPath !== '/profile'" href="/profile" :class="ui.barIcon" :title="`Profile · ${user?.callsign || ''}`"><CircleUser :size="18" /></Link>
                            </div>
                            <div class="border-t border-line pt-1">
                                <button @click="logout" :class="ui.menuRowDanger"><LogOut :size="16" class="shrink-0" /> Logout</button>
                            </div>
                        </nav>
                    </Transition>
                </div>

                <!-- environment badge — only renders when ENV_BADGE is set; TEST = amber (caution), anything else (e.g. BETA) = sky.
                     Doubles as the one-tap feedback trigger: tap it to open the report box from anywhere. -->
                <button v-if="$page.props.envBadge" type="button" @click="reportOpen = true"
                    class="shrink-0 font-mono text-[10px] font-semibold uppercase tracking-wider rounded border px-1.5 py-0.5 hover:brightness-125"
                    :class="$page.props.envBadge === 'TEST' ? 'border-amber-400/50 bg-amber-400/10 text-amber-300 daylight:text-amber-700' : 'border-sky-400/50 bg-sky-400/10 text-sky-300'"
                    :title="$page.props.envBadge === 'TEST' ? 'You\'re on the TEST instance — tap to send feedback' : `You're in the toady ${$page.props.envBadge} — tap to send feedback`">{{ $page.props.envBadge }}</button>

                <!-- op title (centered) -->
                <div class="flex-1 flex items-center justify-center min-w-0 px-2">
                    <slot name="title" />
                </div>

                <!-- op actions (contextual) -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <slot name="actions" />
                </div>

                <!-- settings / indicators -->
                <div class="flex items-center gap-1 shrink-0 pl-2 ml-1 border-l border-line/60">
                    <button @click="goToNotifications" type="button" :class="ui.barIcon" class="relative" title="Notifications">
                        <Inbox :size="18" />
                        <span v-if="unread" class="absolute top-0 right-0 min-w-4 h-4 px-1 rounded-full bg-accent text-[10px] font-mono text-accent-ink flex items-center justify-center leading-none">{{ unread > 9 ? '9+' : unread }}</span>
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-1 py-4">
            <slot />
        </main>

        <Toaster />
        <ReportProblem :open="reportOpen" @close="reportOpen = false" />
    </div>
</template>
