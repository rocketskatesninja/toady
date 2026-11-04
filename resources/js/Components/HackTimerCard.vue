<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Zap, Undo2, Trash2, KeyRound, Minus, Plus } from 'lucide-vue-next';
import ModSlot from '@/Components/ModSlot.vue';
import { useNow } from '@/useLive';
import { useNotify } from '@/useNotify';
import { ui } from '@/ui';
import { timerState, fmtWait, rememberFriendly, useHackAlerts } from '@/hacks';
import { COOLDOWN_FRIENDLY_MIN, COOLDOWN_ENEMY_MIN } from '@/mechanics';

// One hack timer: the countdown, the portal itself — its alignment and its 4 mod slots — then the hack / undo / key
// buttons along the bottom. Shared by the standalone /hacks page and the op's Hack timers widget. Every change goes to
// the server, which owns the game math; the card only counts down against what it sends back.
const props = defineProps({
    timer: { type: Object, required: true },
    keys: { type: Object, default: null },     // op widget: { mine, needed } — this portal's "keys mine"
    showOp: { type: Boolean, default: false }, // standalone page: tag an op timer with its op
});

const now = useNow(250);
const state = computed(() => timerState(props.timer, now.value));
const isPortal = computed(() => props.timer.op_waypoint_id != null); // named by its op portal, not a nickname

// toast (+ buzz, unless this device's push notification does it) when a wait runs out while the page is open —
// not on load, and not when an undo ends it early. With the page closed, toady:hack-alerts pushes it instead.
const { notify } = useNotify();
const { alerts, buzzInPage } = useHackAlerts();
watch(state, (s, prev) => {
    if (alerts.value && s.phase === 'ready' && prev && prev.phase !== 'ready' && prev.waitMs <= 1500) {
        notify(`${props.timer.label} — ready`, prev.phase === 'burnout' ? 'Burnout’s over: a fresh set of hacks.' : 'Cooldown’s over: hack away.', 'success', buzzInPage.value);
    }
});

const busy = ref(false);
function send(method, path = '', data = {}) {
    router[method](`/hacks/${props.timer.id}${path}`, data, {
        preserveScroll: true, preserveState: true,
        onStart: () => { busy.value = true; },
        onFinish: () => { busy.value = false; },
        onError: (errs) => notify('Couldn’t save that', Object.values(errs)[0] || '', 'error'),
    });
}
const hack = () => send('post', '/hack');
const undo = () => send('post', '/undo');
const addKey = (delta) => send('put', '/keys', { delta });
const setSlot = (slot, mod) => send('put', '/slot', { slot, mod });
function setFriendly(v) {
    if (v === props.timer.friendly) return;
    rememberFriendly(v);
    send('put', '', { friendly: v });
}

// tap the name to rename (standalone timers — an op timer is named by its portal)
const renaming = ref(false);
const nameDraft = ref('');
const nameInput = ref(null);
async function startRename() {
    nameDraft.value = props.timer.label || '';
    renaming.value = true;
    await nextTick();
    nameInput.value?.select();
}
function finishRename(save = true) {
    if (!renaming.value) return; // Enter/Esc already finished — ignore the blur that follows
    renaming.value = false;
    if (save && nameDraft.value.trim() !== props.timer.label) send('put', '', { label: nameDraft.value });
}

// two-tap delete
const confirmDel = ref(false);
let delTimer;
function remove() {
    if (!confirmDel.value) { confirmDel.value = true; clearTimeout(delTimer); delTimer = setTimeout(() => (confirmDel.value = false), 3000); return; }
    send('delete');
}

const PHASE = {
    ready: { label: 'ready', text: 'text-accent glow', border: 'border-emerald-500/40', bar: 'bg-accent' },
    cooldown: { label: 'cooldown', text: 'text-amber-300 daylight:text-amber-700', border: 'border-amber-500/40', bar: 'bg-amber-400' },
    burnout: { label: 'burnt out', text: 'text-rose-400', border: 'border-rose-500/40', bar: 'bg-rose-400' },
};
const look = computed(() => PHASE[state.value.phase]);
const barPct = computed(() => {
    const s = state.value;
    if (s.phase === 'cooldown') return 100 * (1 - s.waitMs / s.totalMs);
    if (s.phase === 'burnout') {
        const first = Date.parse(props.timer.hacks[0]);
        return 100 * ((now.value - first) / (s.windowEndsMs - first));
    }
    return 0;
});

const hackBtn = 'flex-1 h-10 inline-flex items-center justify-center gap-1.5 rounded px-2 bg-accent hover:bg-emerald-400 text-accent-ink text-sm font-mono font-semibold uppercase tracking-wide disabled:opacity-40';
const sideBtn = 'h-10 w-9 shrink-0 inline-flex items-center justify-center rounded border border-line text-ink-faint hover:text-accent disabled:opacity-25 disabled:pointer-events-none';
</script>

<template>
    <div class="border rounded-lg bg-surface px-2.5 py-2 space-y-2 transition-colors" :class="look.border">
        <!-- name · delete -->
        <div class="flex items-center gap-1 min-w-0">
            <input v-if="renaming" ref="nameInput" v-model="nameDraft" maxlength="80" placeholder="nickname (optional)" aria-label="Nickname"
                @keydown.enter.prevent="finishRename()" @keydown.esc="finishRename(false)" @blur="finishRename()"
                class="flex-1 min-w-0 h-8 px-1.5 rounded bg-inset border border-accent text-sm text-ink focus:outline-none" />
            <button v-else-if="!isPortal" type="button" @click="startRename" title="Rename"
                class="flex-1 min-w-0 h-8 text-left truncate text-sm text-ink underline decoration-dotted decoration-ink-faint underline-offset-4 hover:decoration-accent">{{ timer.label }}</button>
            <span v-else class="flex-1 min-w-0 truncate text-sm text-ink" :title="timer.label">{{ timer.label }}</span>
            <button v-if="!confirmDel" @click="remove" :class="ui.iconDanger" title="Delete this timer"><Trash2 :size="14" /></button>
            <button v-else @click="remove" class="h-8 shrink-0 px-1.5 rounded text-[10px] font-mono uppercase text-rose-400 border border-rose-500/50">delete?</button>
        </div>
        <Link v-if="showOp && timer.op" :href="`/ops/${timer.op.id}`" class="block -mt-1 truncate text-[10px] font-mono text-ink-faint hover:text-accent" :title="`Op: ${timer.op.name}`">op · {{ timer.op.name }}</Link>

        <!-- clock -->
        <div class="flex items-end justify-between gap-2">
            <div class="min-w-0">
                <div class="text-[10px] font-mono uppercase tracking-wide" :class="look.text">{{ look.label }}</div>
                <div class="text-3xl leading-none font-mono tabular-nums mt-0.5" :class="look.text">{{ state.phase === 'ready' ? 'GO' : fmtWait(state.waitMs) }}</div>
            </div>
            <div class="text-right font-mono shrink-0">
                <div class="text-[10px] uppercase tracking-wide text-ink-faint">hacks</div>
                <div class="text-xl leading-none tabular-nums" :class="state.phase === 'burnout' ? 'text-rose-400' : 'text-ink'">{{ state.used }}<span class="text-ink-faint text-sm">/{{ state.limit }}</span></div>
            </div>
        </div>
        <div class="h-1 rounded-full bg-line/50 overflow-hidden">
            <div class="h-full rounded-full" :class="look.bar" :style="{ width: barPct + '%' }"></div>
        </div>
        <p v-if="state.phase !== 'burnout' && state.windowEndsMs" class="text-[10px] font-mono text-ink-faint">hack count resets in {{ fmtWait(state.windowEndsMs - now) }}</p>

        <!-- the portal: its alignment (sets the base cooldown) and, on the right, its 4 mod slots — on a too-narrow
             card the slots wrap to their own line, still on the right -->
        <div class="flex flex-wrap items-center gap-2">
            <div :class="ui.segFit">
                <button @click="setFriendly(true)" :class="ui.segBtn(timer.friendly)" :title="`Your faction’s portal — ${COOLDOWN_FRIENDLY_MIN} min cooldown`">friendly <span class="normal-case opacity-60">{{ COOLDOWN_FRIENDLY_MIN }}m</span></button>
                <button @click="setFriendly(false)" :class="ui.segBtn(!timer.friendly)" :title="`Enemy or neutral portal — ${COOLDOWN_ENEMY_MIN} min cooldown`">enemy <span class="normal-case opacity-60">{{ COOLDOWN_ENEMY_MIN }}m</span></button>
            </div>
            <div class="ml-auto flex items-center gap-1" role="group" aria-label="Mod slots">
                <ModSlot v-for="(m, i) in timer.mods" :key="i" :mod="m" :index="i" :disabled="busy" @pick="(mod) => setSlot(i, mod)" />
            </div>
        </div>

        <!-- hack + keys -->
        <div class="flex items-center gap-1.5">
            <button @click="hack" :disabled="busy" :class="hackBtn"><Zap :size="15" /> Hacked</button>
            <button @click="undo" :disabled="busy || !state.used" :class="sideBtn" title="Take back the last hack"><Undo2 :size="15" /></button>
            <div class="h-10 shrink-0 flex items-center rounded border border-line" title="Keys farmed on this timer">
                <button @click="addKey(-1)" :disabled="busy || !timer.keys" class="h-full px-1.5 text-ink-faint hover:text-accent disabled:opacity-30" aria-label="One key fewer"><Minus :size="14" /></button>
                <span class="flex items-center gap-1 px-1 font-mono text-sm tabular-nums text-ink"><KeyRound :size="13" class="text-ink-faint" />{{ timer.keys }}</span>
                <button @click="addKey(1)" :disabled="busy" class="h-full px-1.5 text-ink-faint hover:text-accent disabled:opacity-30" aria-label="One more key"><Plus :size="14" /></button>
            </div>
        </div>

        <!-- op timers: this portal's "keys mine" (moved by the key stepper above) vs the op's target -->
        <p v-if="keys" class="-mt-1 text-right text-[10px] font-mono text-ink-faint">
            keys mine <span class="text-ink-dim">{{ keys.mine }}</span><template v-if="keys.needed"> · op needs <span :class="keys.mine >= keys.needed ? 'text-accent' : 'text-ink-dim'">{{ keys.needed }}</span></template>
        </p>
    </div>
</template>
