<script setup>
import { ref, reactive, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ShieldCheck, Mail, MailX, UserMinus, UserCheck, Trash2, X, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import Avatar from '@/Components/Avatar.vue';
import RichText from '@/Components/RichText.vue';

const props = defineProps({
    users: { type: Object, default: () => ({ data: [] }) }, // Laravel paginator
    q: { type: String, default: '' },
    audit: { type: Array, default: () => [] },
    mail: { type: Object, default: () => ({}) },
    optedInCount: { type: Number, default: 0 },
});

const page = usePage();
const isOwner = computed(() => !!page.props.auth?.user?.is_owner);
const rows = computed(() => props.users.data || []);
const search = ref(props.q);
const opts = { preserveScroll: true, preserveState: true };

function doSearch() {
    router.get('/admin/users', { q: search.value }, { ...opts, replace: true });
}
function goPage(url) {
    if (url) router.get(url, {}, opts); // preserveState → selections carry across pages
}

// ---- selection (persists across pages so you can build a cross-page set) ----
const selected = ref([]);
const selectableIds = computed(() => rows.value.filter((u) => !u.is_self).map((u) => u.id));
const allChecked = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selected.value.includes(id)));
function toggle(id) {
    selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id];
}
function toggleAll() {
    selected.value = allChecked.value
        ? selected.value.filter((id) => !selectableIds.value.includes(id))
        : [...new Set([...selected.value, ...selectableIds.value])];
}
function clearSel() { selected.value = []; armed.value = false; }

// ---- bulk suspend / re-enable / delete ----
const armed = ref(false); // two-step confirm for delete
function bulk(action) {
    if (!selected.value.length) return;
    if (action === 'delete' && !armed.value) { armed.value = true; setTimeout(() => (armed.value = false), 4000); return; }
    router.post('/admin/users/bulk', { action, ids: selected.value }, { ...opts, onSuccess: clearSel });
}

// ---- email compose (selected, or all opted-in) ----
const showEmail = ref(false);
const mailMode = ref('selected');
const email = reactive({ subject: '', header: props.mail.header || '', body: '', signature: props.mail.signature || '', format: 'html' });
function openEmail(mode) { mailMode.value = mode; showEmail.value = true; }
const sending = ref(false);
function sendEmail() {
    if (!email.subject.trim() || !email.body.trim()) return;
    sending.value = true;
    router.post('/admin/users/email', { ...email, recipients: mailMode.value === 'all' ? [] : selected.value }, {
        ...opts,
        onSuccess: () => { showEmail.value = false; email.subject = ''; email.body = ''; clearSel(); },
        onFinish: () => (sending.value = false),
    });
}
const canSend = computed(() => email.subject.trim() && email.body.trim() && (mailMode.value === 'all' || selected.value.length));
</script>

<template>
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><ShieldCheck :size="18" /> user management</span></template>

        <div class="max-w-4xl mx-auto space-y-4">
            <AdminNav current="users" />
            <div class="flex flex-wrap items-center gap-2">
                <input v-model="search" @keyup.enter="doSearch" placeholder="search callsign or email…"
                    class="flex-1 min-w-40 bg-inset border border-line rounded px-2 py-1.5 text-sm focus:border-accent focus:outline-none" />
                <button @click="doSearch" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5">search</button>
                <button v-if="isOwner" @click="openEmail('all')" class="inline-flex items-center gap-1 font-mono text-sm rounded border border-accent/40 text-accent hover:bg-emerald-500/10 px-2.5 py-1.5"><Mail :size="14" /> email all <span class="text-ink-faint">({{ optedInCount }})</span></button>
                <span class="text-[11px] font-mono text-ink-faint shrink-0">{{ users.total }} total</span>
            </div>

            <!-- bulk action bar -->
            <div v-if="selected.length" class="flex flex-wrap items-center gap-2 px-3 py-2 border border-accent/40 rounded-lg bg-emerald-500/5">
                <span class="text-xs font-mono text-accent">{{ selected.length }} selected</span>
                <span class="w-px h-4 bg-line"></span>
                <button @click="bulk('suspend')" class="inline-flex items-center gap-1 text-[11px] font-mono rounded border border-line text-ink-dim hover:text-amber-300 hover:border-amber-500/40 px-1.5 py-0.5"><UserMinus :size="13" /> suspend</button>
                <button @click="bulk('unsuspend')" class="inline-flex items-center gap-1 text-[11px] font-mono rounded border border-line text-ink-dim hover:text-accent hover:border-emerald-500/40 px-1.5 py-0.5"><UserCheck :size="13" /> re-enable</button>
                <button v-if="isOwner" @click="bulk('trust')" title="grant trusted catalog contributor" class="inline-flex items-center gap-1 text-[11px] font-mono rounded border border-line text-ink-dim hover:text-sky-300 hover:border-sky-400/40 px-1.5 py-0.5"><ShieldCheck :size="13" /> trust</button>
                <button v-if="isOwner" @click="bulk('untrust')" title="revoke trusted" class="inline-flex items-center gap-1 text-[11px] font-mono rounded border border-line text-ink-dim hover:text-amber-300 px-1.5 py-0.5">untrust</button>
                <button @click="bulk('delete')" class="inline-flex items-center gap-1 text-[11px] font-mono rounded border px-1.5 py-0.5"
                    :class="armed ? 'border-rose-500 bg-rose-500/20 text-rose-200' : 'border-line text-ink-faint hover:text-rose-400 hover:border-rose-500/40'">
                    <Trash2 :size="13" /> {{ armed ? `confirm delete ${selected.length}` : 'delete' }}
                </button>
                <button v-if="isOwner" @click="openEmail('selected')" class="inline-flex items-center gap-1 text-[11px] font-mono rounded border border-accent/40 text-accent hover:bg-emerald-500/10 px-1.5 py-0.5"><Mail :size="13" /> email</button>
                <button @click="clearSel" class="ml-auto text-[11px] font-mono text-ink-faint hover:text-ink">clear</button>
            </div>

            <div class="border border-line rounded-lg bg-surface overflow-hidden">
                <div class="grid grid-cols-[auto_1fr_auto] gap-3 px-3 py-2 border-b border-line text-[10px] font-mono uppercase tracking-wider text-ink-faint items-center">
                    <input type="checkbox" :checked="allChecked" @change="toggleAll" class="accent-emerald-500" title="select everyone on this page" />
                    <span>Agent</span><span class="text-right">Ops · joined</span>
                </div>
                <div v-for="u in rows" :key="u.id" class="grid grid-cols-[auto_1fr_auto] gap-3 items-center px-3 py-2.5 border-b border-line/60 last:border-0"
                    :class="[u.suspended ? 'opacity-60' : '', selected.includes(u.id) ? 'bg-emerald-500/5' : '']">
                    <input type="checkbox" :checked="selected.includes(u.id)" @change="toggle(u.id)" :disabled="u.is_self" class="accent-emerald-500 disabled:opacity-30" />
                    <div class="flex items-center gap-2.5 min-w-0">
                        <Avatar :src="u.avatar" :callsign="u.callsign" :faction="u.faction" :size="34" />
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-sm text-ink truncate">{{ u.callsign || '(no callsign)' }}</span>
                                <span :class="u.faction === 'ENL' ? 'text-accent' : 'text-res'" class="text-[10px] font-mono">[{{ u.faction || '—' }}]</span>
                                <span v-if="u.is_owner" class="text-[9px] font-mono uppercase border border-amber-500/40 text-amber-300 rounded px-1">owner</span>
                                <span v-if="u.is_admin" class="text-[9px] font-mono uppercase border border-emerald-500/40 text-accent rounded px-1">admin</span>
                                <span v-if="u.suspended" class="text-[9px] font-mono uppercase border border-rose-500/40 text-rose-300 rounded px-1">suspended</span>
                                <span v-if="u.trusted" class="text-[9px] font-mono uppercase border border-sky-400/40 text-sky-300 rounded px-1" title="trusted catalog contributor — a single submit auto-verifies a name">trusted</span>
                                <span v-if="u.is_self" class="text-[9px] font-mono uppercase text-ink-faint">you</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-[11px] font-mono text-ink-faint">
                                <component :is="u.opted_out ? MailX : Mail" :size="11" :class="u.opted_out ? 'text-rose-400/70' : 'text-accent/70'" class="shrink-0" :title="u.opted_out ? 'unsubscribed' : 'subscribed to emails'" />
                                <span class="truncate">{{ u.email || 'no email' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right shrink-0 font-mono leading-tight">
                        <div class="text-[11px] text-ink-dim">owns {{ u.ops }} · in {{ u.joined_ops }}</div>
                        <div class="text-[10px] text-ink-faint mt-0.5">joined {{ u.joined }}</div>
                    </div>
                </div>
                <p v-if="!rows.length" class="px-3 py-6 text-center text-xs text-ink-faint">No accounts match.</p>
            </div>

            <!-- pagination -->
            <div v-if="users.last_page > 1" class="flex items-center justify-center gap-3 text-xs font-mono text-ink-dim">
                <button @click="goPage(users.prev_page_url)" :disabled="!users.prev_page_url" class="inline-flex items-center gap-0.5 rounded border border-line px-2 py-1 hover:text-accent hover:border-accent/40 disabled:opacity-30"><ChevronLeft :size="14" /> prev</button>
                <span>page {{ users.current_page }} / {{ users.last_page }} · {{ users.from }}–{{ users.to }} of {{ users.total }}</span>
                <button @click="goPage(users.next_page_url)" :disabled="!users.next_page_url" class="inline-flex items-center gap-0.5 rounded border border-line px-2 py-1 hover:text-accent hover:border-accent/40 disabled:opacity-30">next <ChevronRight :size="14" /></button>
            </div>

            <div v-if="audit.length">
                <h2 class="hud-label font-mono text-[11px] uppercase tracking-wider text-ink-dim mb-2">Recent admin actions</h2>
                <ul class="space-y-1 text-[11px] font-mono text-ink-faint">
                    <li v-for="a in audit" :key="a.id" class="flex gap-2">
                        <span class="text-ink-dim">{{ a.who }}</span>
                        <span class="text-accent/80">{{ a.action }}</span>
                        <span class="text-ink-dim truncate">{{ a.summary }}</span>
                        <span class="ml-auto shrink-0">{{ a.at }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- email compose modal (owner only) -->
        <div v-if="showEmail" class="fixed inset-0 z-[60] flex items-start justify-center overflow-auto bg-black/60 px-3 py-8" @click.self="showEmail = false">
            <div class="w-full max-w-xl bg-surface border border-line rounded-lg shadow-2xl">
                <div class="flex items-center justify-between px-3 py-2.5 border-b border-line">
                    <span class="inline-flex items-center gap-1.5 font-mono text-sm text-accent"><Mail :size="15" /> Compose broadcast</span>
                    <button @click="showEmail = false" title="close" class="text-ink-faint hover:text-ink"><X :size="16" /></button>
                </div>
                <div class="px-3 py-3 space-y-3">
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="font-mono text-ink-faint">To · <span class="text-ink-dim">{{ mailMode === 'all' ? `all ${optedInCount} opted-in player${optedInCount === 1 ? '' : 's'}` : `${selected.length} selected` }}</span> (opt-outs skipped)</span>
                        <div class="flex items-center gap-1 shrink-0">
                            <button @click="email.format = 'html'" :class="email.format === 'html' ? 'border-accent text-accent bg-emerald-500/10' : 'border-line text-ink-faint'" class="font-mono text-[10px] uppercase border rounded px-2 py-0.5">html</button>
                            <button @click="email.format = 'text'" :class="email.format === 'text' ? 'border-accent text-accent bg-emerald-500/10' : 'border-line text-ink-faint'" class="font-mono text-[10px] uppercase border rounded px-2 py-0.5">text</button>
                        </div>
                    </div>
                    <input v-model="email.subject" placeholder="Subject" class="w-full bg-inset border border-line rounded px-2 py-1.5 text-sm focus:border-accent focus:outline-none" />
                    <input v-model="email.header" placeholder="Header / title (optional)" class="w-full bg-inset border border-line rounded px-2 py-1.5 text-sm focus:border-accent focus:outline-none" />
                    <RichText v-model="email.body" />
                    <textarea v-model="email.signature" spellcheck="true" rows="2" placeholder="Signature (optional)" class="w-full bg-inset border border-line rounded px-2 py-1.5 text-sm focus:border-accent focus:outline-none resize-y"></textarea>
                    <p class="text-[10px] font-mono text-ink-faint">{{ email.format === 'html' ? 'Sends a rich HTML email (with a plain-text fallback).' : 'Sends plain text only.' }} Released gradually to protect the mail server.</p>
                </div>
                <div class="flex items-center justify-end gap-2 px-3 py-2.5 border-t border-line">
                    <button @click="showEmail = false" class="text-xs font-mono text-ink-faint hover:text-ink px-2">cancel</button>
                    <button @click="sendEmail" :disabled="sending || !canSend"
                        class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-40">
                        {{ sending ? 'queuing…' : (mailMode === 'all' ? `send to all (${optedInCount})` : `send to ${selected.length}`) }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
