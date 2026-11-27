<script setup>
import { Head, Link } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';

defineProps({ title: { type: String, required: true }, tag: { type: String, default: '' } });
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen text-ink font-sans px-1 py-10">
        <div class="mx-auto max-w-2xl px-3">
            <Link href="/" class="font-mono text-accent glow tracking-[0.3em] text-xl"><BrandMark /> toady</Link>
            <h1 class="mt-6 text-2xl font-mono text-ink">{{ title }}</h1>
            <p v-if="tag" class="text-xs font-mono text-ink-faint mb-8">{{ tag }}</p>

            <div class="space-y-7 text-sm text-ink-dim leading-relaxed">
                <slot />
            </div>

            <nav class="mt-12 pt-5 border-t border-line flex flex-wrap gap-x-5 gap-y-2 text-[11px] font-mono text-ink-faint">
                <Link href="/" class="hover:text-accent">← home</Link>
                <Link href="/beta" class="text-sky-300 hover:text-sky-200">beta</Link>
                <Link v-if="$page.props.showcaseEnabled" href="/showcase" class="hover:text-accent">showcase</Link>
                <Link href="/guide" class="hover:text-accent">guide</Link>
                <Link href="/donate" class="hover:text-accent">support</Link>
                <Link href="/privacy" class="hover:text-accent">privacy</Link>
                <Link href="/terms" class="hover:text-accent">terms</Link>
            </nav>
        </div>
    </div>
</template>
