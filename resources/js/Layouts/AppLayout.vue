<script setup>
import { computed, ref, watch, watchEffect, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Toaster from '@/Components/Toaster.vue';
import PushToggle from '@/Components/PushToggle.vue';
import ReportProblem from '@/Components/ReportProblem.vue';
import BrandMark from '@/Components/BrandMark.vue';
import { useTheme } from '@/useTheme';
import { useFatFinger } from '@/useFatFinger';
import { setNotifyVibrate, useNotify } from '@/useNotify';
import { WIDGET_ICONS, OP_VIEWS } from '@/icons';
import { LayoutGrid, Library, ShieldCheck, BookOpen, Compass, CircleUser, LayoutDashboard, Moon, Sun, ZoomIn, ZoomOut, LogOut, Inbox, Bug, Images } from 'lucide-vue-next';

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

// Remember the op you're on so its menu links persist on other pages (Profile, etc.) —
// AppLayout re-mounts each navigation, so we stash {id,name} in localStorage.
const liveOp = computed(() => page.props.op ?? null);
const rememberedOp = ref(null);
onMounted(() => {
    if (liveOp.value) {
        rememberedOp.value = { id: liveOp.value.id, name: liveOp.value.name };
        try { localStorage.setItem('toady:lastOp', JSON.stringify(rememberedOp.value)); } catch (e) { /* */ }
        return;
    }
    let remembered = null;
    try { remembered = JSON.parse(localStorage.getItem('toady:lastOp') || 'null'); } catch (e) { /* */ }
    // When the ops list is in view (the dashboard), forget a remembered op that's been deleted/closed —
    // otherwise the menu keeps linking to a now-404 op.
    const ops = page.props.ops;
    if (remembered && Array.isArray(ops) && !ops.some((o) => o.id === remembered.id)) {
        remembered = null;
        try { localStorage.removeItem('toady:lastOp'); } catch (e) { /* */ }
    }
    rememberedOp.value = remembered;
});
const currentOp = computed(() => liveOp.value ?? rememberedOp.value);
// True only when the current page IS this op's dashboard grid (so the menu can offer a link back otherwise).
const onOpDashboard = computed(() => {
    if (!currentOp.value) return false;
    const [path, qs] = page.url.split('?');
    return path === `/ops/${currentOp.value.id}` && !new URLSearchParams(qs || '').get('view');
});
// the page you're currently on — used to hide its own menu link (you don't need to navigate to where you are)
const currentPath = computed(() => page.url.split('?')[0]);
const currentView = computed(() => {
    if (!currentOp.value) return null;
    const [path, qs] = page.url.split('?');
    return path === `/ops/${currentOp.value.id}` ? new URLSearchParams(qs || '').get('view') : null;
});
// header bell → the op's notifications: scroll to the widget if it's on the current dashboard, else open the page
function goToNotifications() {
    const op = currentOp.value;
    const el = onOpDashboard.value ? document.getElementById('w-notifications') : null;
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    router.visit(op ? `/ops/${op.id}?view=notifications` : '/dashboard');
}
const { theme, toggle: toggleTheme } = useTheme();
const { on: fatFinger, toggle: toggleFatFinger } = useFatFinger();
const reportOpen = ref(false);

// Header bell: unread count, seeded from the shared prop then polled.
const unread = ref(page.props.unreadNotifications ?? 0);
let unreadTimer = null;
async function pollUnread() {
    try {
        const { data } = await window.axios.get('/notifications/feed');
        const arrived = data.unread > unread.value; // new notification(s) since the last check
        unread.value = data.unread;
        // tell any open panel (notifications widget, DM panel) to reload its list — keeps them current
        // without a page reload even when their own poll was throttled in a backgrounded tab
        if (arrived) window.dispatchEvent(new CustomEvent('toady:notifications-changed'));
    } catch (e) { /* keep last */ }
}
onMounted(() => {
    pollUnread();
    unreadTimer = setInterval(pollUnread, 25000);
    window.addEventListener('toady:notifications-changed', pollUnread); // mark-read/all updates the badge at once
});
onBeforeUnmount(() => {
    clearInterval(unreadTimer);
    window.removeEventListener('toady:notifications-changed', pollUnread);
});

// Op-view widgets that aren't in the mobile grid show as full pages here. Show.vue publishes the
// current mobile grid set to localStorage; we read it (and refresh whenever the menu opens).
// op-view full pages (OP_VIEWS) + their icons (WIDGET_ICONS) come from @/icons — single source
const mobileGrid = ref(['map', 'plan']);
const desktopGrid = ref(['map', 'plan', 'roster', 'dms', 'notes']); // server default desktop set
const isMobile = ref(false);
function readGrids() {
    try { const m = JSON.parse(localStorage.getItem('toady:mobileGrid') || 'null'); if (Array.isArray(m)) mobileGrid.value = m; } catch (e) { /* */ }
    try { const d = JSON.parse(localStorage.getItem('toady:desktopGrid') || 'null'); if (Array.isArray(d)) desktopGrid.value = d; } catch (e) { /* */ }
}
// Page links reflect the CURRENT viewport's grid — any op widget not loaded there opens as a full page.
const activeGrid = computed(() => (isMobile.value ? mobileGrid.value : desktopGrid.value));
const pageViews = computed(() => OP_VIEWS.filter((v) => !activeGrid.value.includes(v.key) && v.key !== currentView.value && (!v.op || currentOp.value?.is_operative)));
let menuMql;
const onMenuMql = (e) => (isMobile.value = e.matches);
// the op page broadcasts its live grid on every change — keep our copy in lock-step so page-links never
// point at a widget that's actually on the grid (which would bounce the user back to the dashboard)
const onGrids = (e) => {
    if (Array.isArray(e.detail?.mobile)) mobileGrid.value = e.detail.mobile;
    if (Array.isArray(e.detail?.desktop)) desktopGrid.value = e.detail.desktop;
};
onMounted(() => {
    readGrids();
    window.addEventListener('toady:grids', onGrids);
    if (window.matchMedia) { menuMql = window.matchMedia('(max-width: 767px)'); isMobile.value = menuMql.matches; menuMql.addEventListener('change', onMenuMql); }
});
onBeforeUnmount(() => { menuMql?.removeEventListener('change', onMenuMql); window.removeEventListener('toady:grids', onGrids); });

const menuOpen = ref(false);
watch(() => page.url, () => (menuOpen.value = false));

// Close on any tap/click outside the menu (its button + dropdown both live inside
// menuRoot). A document listener — not a fixed backdrop — because the header's
// backdrop-blur makes `position: fixed` children cover only the header bar, not
// the whole screen, so an inset-0 overlay never catches taps on the page body.
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

const itemClass = 'flex items-center gap-2 px-3 py-[0.45rem] text-ink-dim hover:bg-emerald-500/10 hover:text-ink';
const barIcon = 'flex items-center justify-center w-9 h-8 rounded text-ink-dim hover:bg-emerald-500/10 hover:text-accent text-base';
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
                        <nav v-if="menuOpen" class="menu-stagger absolute left-0 -mt-3 z-40 w-60 bg-surface border border-line rounded-lg shadow-xl py-1 font-mono text-sm">
                            <!-- global sections, as an icon bar -->
                            <div class="flex items-center gap-0.5 px-2 py-1.5">
                                <Link v-if="currentPath !== '/dashboard'" href="/dashboard" :class="barIcon" title="Ops"><LayoutGrid :size="18" /></Link>
                                <Link v-if="user?.is_owner && currentPath !== '/catalog'" href="/catalog" :class="barIcon" title="Catalog"><Library :size="18" /></Link>
                                <Link v-if="user?.is_admin && currentPath !== '/admin/users'" href="/admin/users" :class="barIcon" title="Admin · users"><ShieldCheck :size="18" /></Link>
                                <Link v-if="user?.is_admin && currentPath !== '/admin/reports'" href="/admin/reports" :class="barIcon" title="Problem reports"><Bug :size="18" /></Link>
                                <Link v-if="user?.is_admin && currentPath !== '/admin/showcase'" href="/admin/showcase" :class="barIcon" title="Showcase gallery"><Images :size="18" /></Link>
                                <Link v-if="currentPath !== '/reference'" href="/reference" :class="barIcon" title="Reference"><BookOpen :size="18" /></Link>
                                <Link v-if="currentPath !== '/guide'" href="/guide" :class="barIcon" title="Guide · run an op"><Compass :size="18" /></Link>
                            </div>

                            <!-- per-op section — page links for widgets not on the current grid (both viewports);
                                 the op action icons stay mobile-only (desktop keeps them in the top bar). -->
                            <div v-if="currentOp" class="border-t border-line pt-1">
                                <div class="px-3 pb-0.5 text-[10px] uppercase tracking-wide text-ink-faint truncate">{{ currentOp.name }}</div>
                                <div class="sm:hidden"><slot name="op-menu" :close="() => (menuOpen = false)" /></div>
                                <Link v-if="!onOpDashboard" :href="`/ops/${currentOp.id}`" :class="itemClass"><LayoutDashboard :size="16" class="shrink-0" /> Op dashboard</Link>
                                <Link v-for="v in pageViews" :key="v.key" :href="`/ops/${currentOp.id}?view=${v.key}`" :class="itemClass"><component :is="WIDGET_ICONS[v.key]" :size="16" class="shrink-0" /> {{ v.label }}</Link>
                            </div>

                            <!-- settings, as an icon bar matching the top one: daylight/night · view size · profile -->
                            <div class="flex items-center gap-0.5 px-2 py-1.5 border-t border-line">
                                <button @click="toggleTheme" :class="barIcon" :title="theme === 'daylight' ? 'Night mode' : 'Daylight mode'"><component :is="theme === 'daylight' ? Moon : Sun" :size="18" /></button>
                                <button @click="toggleFatFinger" :class="barIcon" :title="fatFinger ? 'Compact View' : 'Big View'"><component :is="fatFinger ? ZoomOut : ZoomIn" :size="18" /></button>
                                <PushToggle />
                                <button @click="reportOpen = true; menuOpen = false" :class="barIcon" title="Report a problem"><Bug :size="18" /></button>
                            </div>
                            <div class="border-t border-line pt-1">
                                <Link v-if="currentPath !== '/profile'" href="/profile" :class="itemClass" :title="`Profile · ${user?.callsign || ''}`">
                                    <CircleUser :size="16" class="shrink-0" /> Profile
                                </Link>
                                <button @click="logout" class="flex items-center gap-2 w-full text-left px-3 py-[0.45rem] text-ink-faint hover:bg-rose-500/10 hover:text-rose-300">
                                    <LogOut :size="16" class="shrink-0" /> Logout
                                </button>
                            </div>
                        </nav>
                    </Transition>
                </div>

                <!-- environment badge — only renders when ENV_BADGE is set; TEST = amber (caution), anything else (e.g. BETA) = sky.
                     Doubles as the one-tap feedback trigger: tap it to open the report box from anywhere. -->
                <button v-if="$page.props.envBadge" type="button" @click="reportOpen = true"
                    class="shrink-0 font-mono text-[10px] font-semibold uppercase tracking-wider rounded border px-1.5 py-0.5 hover:brightness-125"
                    :class="$page.props.envBadge === 'TEST' ? 'border-amber-400/50 bg-amber-400/10 text-amber-300' : 'border-sky-400/50 bg-sky-400/10 text-sky-300'"
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
                    <button @click="goToNotifications" type="button" :class="barIcon" class="relative" title="Notifications">
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
