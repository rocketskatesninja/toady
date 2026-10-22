<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import { factionText } from '@/faction';
import { Bug, CheckCircle2, Circle, Trash2, Mail, Globe } from 'lucide-vue-next';

defineProps({ reports: { type: Object, default: () => ({ data: [], links: [] }) } });

function when(at) {
    try { return new Date(at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return at; }
}
function resolve(r) { router.put(`/admin/reports/${r.id}/resolve`, {}, { preserveScroll: true }); }
function remove(r) { if (confirm('Delete this report and its screenshots?')) router.delete(`/admin/reports/${r.id}`, { preserveScroll: true }); }
</script>

<template>
    <Head title="Problem reports" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><Bug :size="18" /> problem reports</span></template>

        <div class="max-w-3xl mx-auto">
            <AdminNav current="reports" class="mb-4" />

            <div v-for="r in reports.data" :key="r.id" class="border border-line rounded-lg bg-surface p-3 mb-3" :class="r.resolved ? 'opacity-60' : ''">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-sm text-ink">{{ r.callsign || 'unknown' }}</span>
                    <span v-if="r.faction" :class="factionText(r.faction)" class="text-[10px] font-mono">[{{ r.faction }}]</span>
                    <span class="text-[11px] font-mono text-ink-faint">{{ when(r.at) }}</span>
                    <span v-if="r.resolved" class="ml-auto text-[10px] font-mono uppercase text-accent border border-emerald-500/40 rounded px-1.5 py-0.5">resolved</span>
                </div>

                <p class="text-sm text-ink-dim whitespace-pre-line break-words">{{ r.message }}</p>

                <div v-if="r.url" class="mt-2 flex items-center gap-1.5 text-[11px] font-mono text-ink-faint truncate"><Globe :size="12" class="shrink-0" /> {{ r.url }}</div>

                <div v-if="r.shots" class="mt-2 flex flex-wrap gap-2">
                    <a v-for="i in r.shots" :key="i" :href="`/admin/reports/${r.id}/file/${i - 1}`" target="_blank" rel="noopener"
                        class="w-20 h-20 rounded border border-line overflow-hidden hover:border-accent">
                        <img :src="`/admin/reports/${r.id}/file/${i - 1}`" class="w-full h-full object-cover" alt="screenshot" />
                    </a>
                </div>

                <div class="mt-3 flex items-center gap-3 border-t border-line/60 pt-2">
                    <a v-if="r.reply_email" :href="`mailto:${r.reply_email}?subject=Re: your toady report`" class="inline-flex items-center gap-1.5 text-xs font-mono text-accent hover:underline"><Mail :size="13" /> {{ r.reply_email }}</a>
                    <span v-else class="text-[11px] font-mono text-ink-faint">no reply email</span>
                    <button @click="resolve(r)" class="ml-auto inline-flex items-center gap-1 text-xs font-mono text-ink-dim hover:text-accent">
                        <component :is="r.resolved ? Circle : CheckCircle2" :size="14" /> {{ r.resolved ? 'reopen' : 'resolve' }}
                    </button>
                    <button @click="remove(r)" class="inline-flex items-center gap-1 text-xs font-mono text-ink-faint hover:text-rose-400"><Trash2 :size="13" /> delete</button>
                </div>
            </div>

            <p v-if="!reports.data.length" class="text-center text-ink-faint text-sm py-16">No reports yet — all clear.</p>

            <div v-if="reports.links && reports.data.length" class="flex flex-wrap gap-1 mt-4">
                <Link v-for="l in reports.links" :key="l.label" :href="l.url || ''" :class="[l.active ? 'text-accent border-accent' : 'text-ink-dim border-line', !l.url ? 'opacity-30 pointer-events-none' : '']" class="text-xs font-mono border rounded px-1.5 py-1" v-html="l.label" preserve-scroll />
            </div>
        </div>
    </AppLayout>
</template>
