<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({ callsign: '', faction: 'ENL' });
function submit() { form.post('/onboard'); }
</script>

<template>
    <Head title="Set up your agent" />
    <AuthLayout subtitle="AGENT SETUP">
        <form @submit.prevent="submit" class="border border-emerald-500/20 rounded-lg bg-surface px-1.5 py-5 space-y-4">
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">CALLSIGN</label>
                <input v-model="form.callsign" type="text" autofocus
                    class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm focus:border-accent focus:outline-none" />
                <p v-if="form.errors.callsign" class="text-rose-400 text-xs mt-1">{{ form.errors.callsign }}</p>
                <p class="text-ink-faint text-[11px] mt-1">Letters, numbers, underscore — your in-game codename.</p>
            </div>

            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">FACTION</label>
                <div class="flex gap-2">
                    <button type="button" @click="form.faction = 'ENL'"
                        :class="form.faction === 'ENL' ? 'border-accent bg-emerald-500/15 text-accent' : 'border-line text-ink-dim'"
                        class="flex-1 border rounded py-2 text-sm font-mono">ENL</button>
                    <button type="button" @click="form.faction = 'RES'"
                        :class="form.faction === 'RES' ? 'border-res bg-res/15 text-res' : 'border-line text-ink-dim'"
                        class="flex-1 border rounded py-2 text-sm font-mono">RES</button>
                </div>
                <p v-if="form.errors.faction" class="text-rose-400 text-xs mt-1">{{ form.errors.faction }}</p>
            </div>

            <button type="submit" :disabled="form.processing"
                class="w-full bg-accent hover:bg-emerald-400 text-accent-ink font-mono font-semibold rounded py-2 text-sm tracking-wide disabled:opacity-50">
                DEPLOY
            </button>
        </form>
    </AuthLayout>
</template>
