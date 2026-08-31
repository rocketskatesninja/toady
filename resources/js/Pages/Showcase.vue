<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Tag } from 'lucide-vue-next';
import BrandMark from '@/Components/BrandMark.vue';

defineProps({
    entries: { type: Array, default: () => [] },
    submitEmail: { type: String, default: null },
});

const factionClass = (f) => (f === 'RES' ? 'text-res border-res/40' : 'text-enl border-enl/40');
const mailto = (email) => (email ? `mailto:${email}?subject=${encodeURIComponent('My toady op')}` : undefined);
</script>

<template>
    <Head title="Showcase — ops built with toady" />
    <div class="min-h-screen text-ink font-sans">
        <header class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <Link href="/" class="font-mono text-accent glow tracking-[0.3em] text-lg"><BrandMark /> toady</Link>
            <nav class="flex items-center gap-4 text-[11px] font-mono text-ink-faint">
                <Link href="/guide" class="hover:text-accent hidden sm:inline">guide</Link>
                <Link href="/login" class="hover:text-accent">sign in</Link>
            </nav>
        </header>

        <section class="max-w-5xl mx-auto px-4 pt-8 pb-10">
            <div class="flex items-center gap-2 text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-3">
                <span class="w-6 h-px bg-accent/40"></span> From the field
            </div>
            <h1 class="font-mono font-semibold text-3xl sm:text-4xl tracking-tight text-ink">Ops built with toady.</h1>
            <p class="mt-3 text-ink-dim max-w-2xl leading-relaxed">
                Fields thrown, missions run — and the stories from the agents who ran them. Got one of your own?
                <a :href="mailto(submitEmail)" class="text-accent hover:underline">Email us an op screenshot, a couple shots of the crew, and the story</a>
                and we'll add it to the wall.
            </p>
        </section>

        <section class="max-w-5xl mx-auto px-4 pb-24 space-y-10">
            <article v-for="e in entries" :key="e.id" class="border border-line rounded-xl bg-surface overflow-hidden">
                <div v-if="e.images.length" class="grid gap-px bg-line/60"
                    :class="e.images.length === 1 ? 'grid-cols-1' : e.images.length === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3'">
                    <a v-for="(src, i) in e.images" :key="i" :href="src" target="_blank" rel="noopener"
                        :title="`${e.title} — open photo ${i + 1} in a new tab`" class="block group relative">
                        <img :src="src" :alt="`${e.title} — ${i + 1}`" loading="lazy"
                            class="w-full aspect-[4/3] object-cover bg-bg transition group-hover:opacity-90" />
                    </a>
                </div>
                <div class="p-5">
                    <h2 class="font-mono text-ink text-xl tracking-tight">{{ e.title }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-mono text-ink-faint">
                        <span v-if="e.credit">by {{ e.credit }}</span>
                        <span v-if="e.date">· {{ e.date }}</span>
                    </div>
                    <p v-if="e.story" class="mt-3 text-ink-dim leading-relaxed whitespace-pre-line">{{ e.story }}</p>
                    <div v-if="e.tagged.length" class="mt-4 flex flex-wrap items-center gap-1.5">
                        <Tag :size="12" class="text-ink-faint" />
                        <span v-for="t in e.tagged" :key="t.callsign" class="text-[10px] font-mono border rounded px-1.5 py-0.5" :class="factionClass(t.faction)">{{ t.callsign }}</span>
                    </div>
                </div>
            </article>

            <div v-if="!entries.length" class="border border-dashed border-line rounded-xl px-4 py-16 text-center text-ink-faint">
                No field reports yet — be the first.
                <a :href="mailto(submitEmail)" class="text-accent hover:underline">Send yours in.</a>
            </div>
        </section>

        <footer class="border-t border-line/60">
            <div class="max-w-5xl mx-auto px-4 py-8 flex items-center justify-between text-[11px] font-mono text-ink-faint">
                <Link href="/" class="text-accent/70 tracking-[0.3em]"><BrandMark :glow="false" size="1em" /> toady</Link>
                <nav class="flex items-center gap-4">
                    <Link href="/guide" class="hover:text-accent">guide</Link>
                    <Link href="/donate" class="hover:text-accent">support</Link>
                    <Link href="/privacy" class="hover:text-accent">privacy</Link>
                    <Link href="/terms" class="hover:text-accent">terms</Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
