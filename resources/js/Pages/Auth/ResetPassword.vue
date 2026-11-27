<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';

const props = defineProps({ email: String, token: String });
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
function submit() { form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') }); }
</script>

<template>
    <Head title="Reset password" />
    <AuthLayout subtitle="NEW PASSWORD" brand-href="/login">
        <form @submit.prevent="submit" class="border border-emerald-500/20 rounded-lg bg-surface px-1.5 py-4 space-y-3">
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">EMAIL</label>
                <input v-model="form.email" type="email" autocomplete="email"
                    class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm focus:border-accent focus:outline-none" />
                <p v-if="form.errors.email" class="text-rose-400 text-xs mt-1">{{ form.errors.email }}</p>
            </div>
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">NEW PASSWORD</label>
                <PasswordInput v-model="form.password" autocomplete="new-password" autofocus />
                <p v-if="form.errors.password" class="text-rose-400 text-xs mt-1">{{ form.errors.password }}</p>
            </div>
            <div>
                <label class="block text-xs font-mono text-ink-dim mb-1">CONFIRM PASSWORD</label>
                <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
            </div>
            <button type="submit" :disabled="form.processing"
                class="w-full bg-accent hover:bg-emerald-400 text-accent-ink font-mono font-semibold rounded py-2 text-sm tracking-wide disabled:opacity-50">
                Reset password
            </button>
        </form>
    </AuthLayout>
</template>
