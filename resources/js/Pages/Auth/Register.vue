<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import GoogleButton from '@/Components/GoogleButton.vue';
import PasswordInput from '@/Components/PasswordInput.vue';

const form = useForm({ email: '', password: '', password_confirmation: '' });
function submit() { form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') }); }
</script>

<template>
    <Head title="Create account" />
    <AuthLayout subtitle="CREATE ACCOUNT" brand-href="/">
        <div class="mb-3"><GoogleButton label="Continue with Google" /></div>

        <div class="flex items-center gap-2 my-3 text-[10px] font-mono text-ink-faint uppercase tracking-widest">
            <span class="flex-1 h-px bg-line"></span>or<span class="flex-1 h-px bg-line"></span>
        </div>

        <form @submit.prevent="submit" class="border border-emerald-500/20 rounded-lg bg-surface px-1.5 py-4 space-y-3">
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">EMAIL</label>
                <input v-model="form.email" type="email" autocomplete="email" autofocus
                    class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm focus:border-accent focus:outline-none" />
                <p v-if="form.errors.email" class="text-rose-400 text-xs mt-1">{{ form.errors.email }}</p>
            </div>
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">PASSWORD</label>
                <PasswordInput v-model="form.password" autocomplete="new-password" />
                <p v-if="form.errors.password" class="text-rose-400 text-xs mt-1">{{ form.errors.password }}</p>
            </div>
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">CONFIRM PASSWORD</label>
                <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
            </div>
            <button type="submit" :disabled="form.processing"
                class="w-full bg-accent hover:bg-emerald-400 text-accent-ink font-mono font-semibold rounded py-2 text-sm tracking-wide disabled:opacity-50">
                Create account
            </button>
            <p class="text-[11px] text-ink-faint text-center">We'll email you a link to verify your address.</p>
        </form>

        <p class="text-center text-xs text-ink-faint mt-4">
            Already have an account? <Link href="/login" class="text-accent hover:underline">Sign in</Link>
        </p>
    </AuthLayout>
</template>
