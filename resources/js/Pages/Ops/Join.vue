<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';
import AuthLayout from '@/Layouts/AuthLayout.vue';

// An invite link only asks — joining takes this button, so a link alone can't add you to someone's op.
const props = defineProps({ token: String, name: String, owner: String });
const form = useForm({});
function join() { form.post(`/j/${props.token}`); }
</script>

<template>
    <Head :title="`Join ${name}`" />
    <AuthLayout subtitle="OP INVITE">
        <div class="text-center">
            <div class="border border-emerald-500/20 rounded-lg bg-surface px-3 py-5 space-y-4">
                <UserPlus :size="32" class="mx-auto text-accent" :stroke-width="1.5" />
                <p class="text-sm text-ink-dim leading-relaxed">
                    Operator <span class="text-ink font-mono">{{ owner }}</span> invited you to<br>
                    <span class="text-ink font-mono break-words">{{ name }}</span>
                </p>
                <p class="text-[11px] text-ink-faint leading-relaxed">
                    Everyone on this op's roster will see your callsign and the contact details on your profile
                    (phone, Telegram); its operators also see your emergency contact. Only join ops from people you know.
                </p>
                <button @click="join" :disabled="form.processing"
                    class="w-full bg-accent hover:bg-emerald-400 text-accent-ink font-mono font-semibold rounded py-2 text-sm disabled:opacity-50">
                    Join this op
                </button>
                <Link href="/dashboard" class="block text-xs text-ink-faint hover:text-ink font-mono">not now</Link>
            </div>
        </div>
    </AuthLayout>
</template>
