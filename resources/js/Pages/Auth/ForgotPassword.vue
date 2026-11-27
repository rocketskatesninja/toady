<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps({ status: String });
const form = useForm({ email: '' });
function submit() { form.post('/forgot-password'); }
</script>

<template>
    <Head title="Forgot password" />
    <AuthLayout subtitle="RESET PASSWORD" brand-href="/login">
        <p v-if="status" class="text-accent text-xs text-center mb-3">{{ status }}</p>

        <form @submit.prevent="submit" class="border border-emerald-500/20 rounded-lg bg-surface px-1.5 py-4 space-y-3">
            <p class="text-xs text-ink-dim leading-relaxed">Enter your email and we'll send you a link to reset your password.</p>
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">EMAIL</label>
                <input v-model="form.email" type="email" autocomplete="email" autofocus
                    class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm focus:border-accent focus:outline-none" />
                <p v-if="form.errors.email" class="text-rose-400 text-xs mt-1">{{ form.errors.email }}</p>
            </div>
            <button type="submit" :disabled="form.processing"
                class="w-full bg-accent hover:bg-emerald-400 text-accent-ink font-mono font-semibold rounded py-2 text-sm tracking-wide disabled:opacity-50">
                Email reset link
            </button>
        </form>

        <p class="text-center text-xs text-ink-faint mt-4">
            <Link href="/login" class="text-accent hover:underline">Back to sign in</Link>
        </p>
    </AuthLayout>
</template>
