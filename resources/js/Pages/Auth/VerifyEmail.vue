<script setup>
import { useForm, router, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { MailCheck } from 'lucide-vue-next';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps({ email: String });
const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({});
function resend() { form.post('/email/verification-notification'); }
function logout() { router.post('/logout'); }
</script>

<template>
    <Head title="Verify your email" />
    <AuthLayout subtitle="VERIFY EMAIL">
        <div class="text-center">
            <div class="border border-emerald-500/20 rounded-lg bg-surface px-1.5 py-5 space-y-4">
                <MailCheck :size="32" class="mx-auto text-accent" :stroke-width="1.5" />
                <p class="text-sm text-ink-dim leading-relaxed">
                    We sent a verification link to<br><span class="text-ink font-mono break-all">{{ email }}</span>.<br>
                    Click it to activate your account.
                </p>
                <p v-if="flash.success" class="text-accent text-xs">{{ flash.success }}</p>
                <button @click="resend" :disabled="form.processing"
                    class="w-full bg-accent hover:bg-emerald-400 text-accent-ink font-mono font-semibold rounded py-2 text-sm disabled:opacity-50">
                    Resend the link
                </button>
                <button @click="logout" class="text-xs text-ink-faint hover:text-rose-400 font-mono">use a different account</button>
            </div>
            <p class="text-[11px] text-ink-faint mt-4">Check your spam folder if it doesn't arrive in a minute.</p>
        </div>
    </AuthLayout>
</template>
