<script setup>
import { ref, watch, onMounted, nextTick } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, Library } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    portals: Object,
    regions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    focus: { type: Number, default: null }, // deep-link from the map overlay → scroll to + flash this portal
    total: Number,
});

// A portal deep-linked from the map overlay: scroll it into view and flash it briefly.
const flash = ref(null);
function focusPortal(id) {
    if (!id) return;
    nextTick(() => {
        document.getElementById('cat-' + id)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        flash.value = id;
        setTimeout(() => { if (flash.value === id) flash.value = null; }, 2600);
    });
}
onMounted(() => focusPortal(props.focus));
watch(() => props.focus, focusPortal);

const q = ref(props.filters.q || '');
const region = ref(props.filters.region || '');
const filter = ref(props.filters.filter || '');
let t;
watch([q, region, filter], () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/catalog', { q: q.value, region: region.value, filter: filter.value }, { preserveState: true, replace: true }), 250);
});

// crowd-sourced trust state per portal
const STATUS = {
    verified: { label: 'verified', cls: 'text-accent border-accent/40' },
    owner_locked: { label: 'locked', cls: 'text-sky-300 border-sky-400/40' },
    unverified: { label: 'unverified', cls: 'text-amber-300 border-amber-400/40' },
    hidden: { label: 'disputed', cls: 'text-rose-300 border-rose-400/40' },
};
function lock(p) { router.post(`/catalog/portals/${p.id}/lock`, {}, { preserveScroll: true }); }
function restore(p) { router.post(`/catalog/portals/${p.id}/restore`, {}, { preserveScroll: true }); }

// add portal — accepts a pasted Intel/Maps link or raw "lat,lng"
const adding = ref(false);
const link = ref('');
const form = useForm({ title: '', lat: '', lng: '', region: '', gate_pin: '', parking: '', access_notes: '', hazards: '' });
function parseLatLng(s) {
    const m = s.match(/pll=(-?\d+\.\d+),(-?\d+\.\d+)/)
        || s.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/)
        || s.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/)
        || s.match(/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/);
    return m ? { lat: parseFloat(m[1]), lng: parseFloat(m[2]) } : null;
}
watch(link, (v) => {
    const c = parseLatLng(v || '');
    if (c) { form.lat = c.lat; form.lng = c.lng; }
});
function add() {
    form.post('/catalog/portals', { onSuccess: () => { adding.value = false; form.reset(); link.value = ''; } });
}

// inline intel edit
const editing = ref(null);
const eform = useForm({ title: '', gate_pin: '', parking: '', hours: '', access_notes: '', hazards: '' });
function openEdit(p) {
    editing.value = editing.value === p.id ? null : p.id;
    if (editing.value) Object.assign(eform, { title: p.title, gate_pin: p.gate_pin, parking: p.parking, hours: p.hours, access_notes: p.access_notes, hazards: p.hazards });
}
function saveEdit(p) { eform.put(`/catalog/portals/${p.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) }); }
function remove(p) { if (confirm(`Remove “${p.title}”?`)) router.delete(`/catalog/portals/${p.id}`, { preserveScroll: true }); }

// form field styling — labeled fields, each sized to its data (a gate PIN isn't full width)
const inp = 'bg-inset border border-line rounded px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none';
const lbl = 'text-[10px] font-mono uppercase tracking-wide text-ink-faint';
</script>

<template>
    <Head title="Catalog" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><Library :size="18" /> Master catalog <span class="text-ink-faint text-sm font-normal">· {{ total }}</span></span></template>
        <template #actions>
            <button @click="adding = !adding" class="text-xs font-mono bg-accent hover:bg-emerald-400 text-accent-ink font-semibold rounded px-2 py-1">+ portal</button>
        </template>

        <form v-if="adding" @submit.prevent="add" class="border border-emerald-500/30 rounded-lg bg-surface p-4 mb-4 space-y-3">
            <label class="flex flex-col gap-1">
                <span :class="lbl">Portal name</span>
                <input v-model="form.title" :class="inp" class="w-full max-w-md" />
            </label>
            <label class="flex flex-col gap-1">
                <span :class="lbl">Location</span>
                <input v-model="link" placeholder="paste an intel.ingress.com / Google Maps link, or lat,lng" :class="inp" class="w-full font-mono" />
            </label>
            <div class="flex flex-wrap gap-3">
                <label class="flex flex-col gap-1"><span :class="lbl">Lat</span><input v-model="form.lat" :class="inp" class="w-32 font-mono" /></label>
                <label class="flex flex-col gap-1"><span :class="lbl">Lng</span><input v-model="form.lng" :class="inp" class="w-32 font-mono" /></label>
                <label class="flex flex-col gap-1"><span :class="lbl">Region</span><input v-model="form.region" :class="inp" class="w-36" /></label>
            </div>
            <div class="flex flex-wrap gap-3">
                <label class="flex flex-col gap-1"><span :class="lbl">Gate PIN</span><input v-model="form.gate_pin" :class="inp" class="w-28 font-mono" /></label>
                <label class="flex flex-col gap-1 flex-1 min-w-48"><span :class="lbl">Parking</span><input v-model="form.parking" :class="inp" class="w-full" /></label>
            </div>
            <div class="flex items-center gap-2 pt-1">
                <button type="submit" :disabled="form.processing || !form.lat" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-50">Add portal</button>
                <button type="button" @click="adding = false" class="text-xs text-ink-faint hover:text-ink px-2">cancel</button>
                <span v-if="form.errors.lat" class="text-rose-400 text-xs">{{ form.errors.lat }}</span>
            </div>
        </form>

        <div class="flex flex-wrap items-center gap-2 mb-3">
            <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">show</span>
            <button @click="filter = ''" :class="filter === '' ? 'border-accent text-accent' : 'border-line text-ink-dim'" class="text-xs font-mono border rounded px-1.5 py-1">all</button>
            <button @click="filter = 'unverified'" :class="filter === 'unverified' ? 'border-amber-400 text-amber-300' : 'border-line text-ink-dim'" class="text-xs font-mono border rounded px-1.5 py-1">unverified <span class="text-ink-faint">{{ counts.unverified || 0 }}</span></button>
            <button @click="filter = 'flagged'" :class="filter === 'flagged' ? 'border-rose-400 text-rose-300' : 'border-line text-ink-dim'" class="text-xs font-mono border rounded px-1.5 py-1">flagged <span class="text-ink-faint">{{ counts.flagged || 0 }}</span></button>
            <button @click="filter = 'hidden'" :class="filter === 'hidden' ? 'border-rose-400 text-rose-300' : 'border-line text-ink-dim'" class="text-xs font-mono border rounded px-1.5 py-1">hidden <span class="text-ink-faint">{{ counts.hidden || 0 }}</span></button>
        </div>

        <div class="flex flex-wrap items-center gap-2 mb-4">
            <input v-model="q" placeholder="search portals…" class="flex-1 min-w-48 bg-inset border border-line rounded px-1.5 py-1.5 text-sm focus:border-accent focus:outline-none" />
            <button @click="region = ''" :class="region === '' ? 'border-accent text-accent' : 'border-line text-ink-dim'" class="text-xs font-mono border rounded px-1.5 py-1">all</button>
            <button v-for="r in regions" :key="r.region" @click="region = r.region" :class="region === r.region ? 'border-accent text-accent' : 'border-line text-ink-dim'" class="text-xs font-mono border rounded px-1.5 py-1">{{ r.region }} <span class="text-ink-faint">{{ r.n }}</span></button>
        </div>

        <div class="border border-line rounded-lg bg-surface overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="p in portals.data" :key="p.id" :id="'cat-' + p.id" class="px-1.5 py-2.5 transition-colors duration-500" :class="flash === p.id ? 'bg-emerald-500/15' : ''">
                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm text-ink truncate">{{ p.title || '(untitled)' }}
                                <span v-if="p.status && STATUS[p.status]" class="text-[9px] font-mono uppercase border rounded px-1 ml-1" :class="STATUS[p.status].cls">{{ STATUS[p.status].label }}</span>
                                <span v-if="p.contributors" class="text-[10px] font-mono text-ink-faint ml-1" title="independent contributors">· {{ p.contributors }} src</span>
                                <span v-if="p.flags" class="text-[10px] font-mono text-rose-300 ml-1" title="flags">⚑ {{ p.flags }}</span>
                                <span v-if="p.has_intel" class="text-[10px] text-amber-400/80 font-mono ml-1">◆ intel</span>
                            </div>
                            <div class="text-xs font-mono text-ink-faint">{{ p.lat.toFixed(5) }}, {{ p.lng.toFixed(5) }} <span v-if="p.region">· {{ p.region }}</span></div>
                        </div>
                        <a :href="`https://intel.ingress.com/?pll=${p.lat},${p.lng}`" target="_blank" class="inline-flex items-center gap-0.5 text-[11px] font-mono text-accent">intel <ExternalLink :size="12" /></a>
                        <button v-if="p.status === 'hidden'" @click="restore(p)" title="restore — clears flags, marks verified" class="text-ink-faint hover:text-accent text-xs font-mono">restore</button>
                        <button v-else-if="p.status !== 'owner_locked'" @click="lock(p)" title="lock this name — freeze from consensus" class="text-ink-faint hover:text-sky-300 text-xs font-mono">lock</button>
                        <button @click="openEdit(p)" class="text-ink-faint hover:text-accent text-xs font-mono">edit</button>
                        <button @click="remove(p)" title="remove portal" class="text-ink-faint hover:text-rose-400 text-xs">×</button>
                    </div>
                    <form v-if="editing === p.id" @submit.prevent="saveEdit(p)" class="mt-3 border-t border-line pt-3 space-y-3">
                        <label class="flex flex-col gap-1">
                            <span :class="lbl">Portal name</span>
                            <input v-model="eform.title" :class="inp" class="w-full max-w-md" />
                        </label>
                        <div class="flex flex-wrap gap-3">
                            <label class="flex flex-col gap-1"><span :class="lbl">Gate PIN</span><input v-model="eform.gate_pin" :class="inp" class="w-28 font-mono" /></label>
                            <label class="flex flex-col gap-1"><span :class="lbl">Hours</span><input v-model="eform.hours" placeholder="24/7, dawn–dusk…" :class="inp" class="w-40" /></label>
                            <label class="flex flex-col gap-1 flex-1 min-w-48"><span :class="lbl">Parking</span><input v-model="eform.parking" :class="inp" class="w-full" /></label>
                        </div>
                        <label class="flex flex-col gap-1"><span :class="lbl">Access notes</span><textarea v-model="eform.access_notes" rows="2" :class="inp" class="w-full resize-y"></textarea></label>
                        <label class="flex flex-col gap-1"><span :class="lbl">Hazards</span><textarea v-model="eform.hazards" rows="2" :class="inp" class="w-full resize-y"></textarea></label>
                        <div class="flex gap-2 pt-1">
                            <button type="submit" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-xs font-semibold rounded px-3 py-1.5">Save</button>
                            <button type="button" @click="editing = null" class="text-xs text-ink-faint px-2 hover:text-ink">cancel</button>
                        </div>
                    </form>
                </li>
            </ul>
        </div>

        <div v-if="portals.links" class="flex flex-wrap gap-1 mt-4">
            <Link v-for="l in portals.links" :key="l.label" :href="l.url || ''" :class="[l.active ? 'text-accent border-accent' : 'text-ink-dim border-line', !l.url ? 'opacity-30 pointer-events-none' : '']" class="text-xs font-mono border rounded px-1.5 py-1" v-html="l.label" preserve-scroll />
        </div>
    </AppLayout>
</template>
