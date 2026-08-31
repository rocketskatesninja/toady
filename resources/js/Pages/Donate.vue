<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DocLayout from '@/Layouts/DocLayout.vue';
import { Coffee } from 'lucide-vue-next';

const donateUrl = computed(() => usePage().props.donateUrl);
</script>

<template>
    <DocLayout title="Support toady" tag="Free forever. Tips just keep the lights on.">
        <p>
            toady is free, runs no ads, and shows no trackers once you sign in — and it always will.
            But the servers, the map &amp; routing services, and the AI features cost real money to keep running.
            If toady's helped you run a smoother op, a small tip keeps it alive and getting better.
            <span class="text-ink">No paywalls, no locked features — everything stays free either way.</span>
        </p>

        <div class="flex flex-col items-center gap-3 py-3">
            <a v-if="donateUrl" :href="donateUrl" target="_blank" rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-lg border border-accent/50 bg-emerald-500/10 px-5 py-3 font-mono text-accent hover:bg-emerald-500/20 glow">
                <Coffee :size="18" /> Support toady on Ko-fi
            </a>
            <p v-else class="text-xs font-mono text-ink-faint">Donations open soon — check back shortly.</p>
            <p class="text-[11px] font-mono text-ink-faint">One-time or monthly · processed securely by Ko-fi · cancel anytime</p>
        </div>

        <section>
            <h2 class="hud-label font-mono text-sm uppercase tracking-wider text-ink-dim mb-2">Where it goes</h2>
            <ul class="space-y-2 list-none">
                <li><span class="text-accent">Servers.</span> Hosting toady and keeping it fast and online.</li>
                <li><span class="text-accent">Maps &amp; routes.</span> The tile, traffic, and walking-route services the map relies on.</li>
                <li><span class="text-accent">Portal catalog.</span> Building and maintaining the regional portal library.</li>
                <li><span class="text-accent">New features.</span> The time to keep making toady better for your ops.</li>
            </ul>
        </section>

        <p class="text-xs text-ink-faint">
            Thank you — seriously. Even a coffee's worth means a lot.
            <span class="text-ink">Not affiliated with or endorsed by Niantic; toady is an independent, fan-made tool.</span>
        </p>
    </DocLayout>
</template>
