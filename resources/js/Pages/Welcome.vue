<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GoogleButton from '@/Components/GoogleButton.vue';
import HeroScanner from '@/Components/landing/HeroScanner.vue';
import BrandMark from '@/Components/BrandMark.vue';
import {
    Target, Share2, Radio, Trash2, Map, KeyRound, Waypoints, MessagesSquare,
    CloudSun, Bell, LayoutGrid, Users, Bot, ArrowRight, ShieldOff, Check, ClipboardList,
} from 'lucide-vue-next';

defineProps({ canLogin: { type: Boolean, default: true } });

const steps = [
    { n: '01', icon: Target, t: 'Build the plan', b: 'Pull portals from your catalog or paste an IITC export. Tag anchors + spines and auto-build the whole fan in one tap — or set objectives by hand.' },
    { n: '02', icon: Share2, t: 'Share the link', b: 'One join link. Send it anywhere — agents tap it, sign in, and they’re on the op.' },
    { n: '03', icon: Radio, t: 'Run it live', b: 'Live map, directives, key locker, comms — your whole team on the same scanner, in real time.' },
    { n: '04', icon: Trash2, t: 'Stand down', b: 'Close the op and every byte is purged. No history, no cleanup. It self-destructs.' },
];
const features = [
    { icon: Map, t: 'Live map & presence', b: 'Every agent’s position on the scanner in real time — plus any agent’s walking route with its on-foot ETA.' },
    { icon: Target, t: 'Mission directives', b: 'Assign tasks; agents get a “your turn” ping when they’re up.' },
    { icon: KeyRound, t: 'Key locker', b: 'Track who holds what, per portal, across the team.' },
    { icon: Waypoints, t: 'One-tap fan fields', b: 'Tag anchors + spines, hit auto — every link, key target, and assignment laid out for you. Or import straight from IITC.' },
    { icon: MessagesSquare, t: 'Comms', b: 'Op chat, 1:1 DMs, and @mentions that ping.' },
    { icon: CloudSun, t: 'Overlays', b: 'Weather and live traffic, right on the map.' },
    { icon: Bell, t: 'Notifications', b: 'A feed plus background push for what actually matters.' },
    { icon: LayoutGrid, t: 'Your war-room', b: 'Drag, resize, and page the widgets however you run.' },
    { icon: Bot, t: 'AI Concierge', b: 'Bring your own AI key — it knows toady and your live op, and finds parking, tides, and routes to reach any portal.' },
];

// lightweight scroll-reveal
const root = ref(null);
let io = null;
onMounted(() => {
    if (!('IntersectionObserver' in window)) return;
    io = new IntersectionObserver((entries) => {
        entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.12 });
    root.value?.querySelectorAll('.reveal').forEach((el) => io.observe(el));
});
onBeforeUnmount(() => io?.disconnect());
</script>

<template>
    <Head title="toady — multiplayer mission command for Ingress" />
    <div ref="root" class="min-h-screen text-ink font-sans">
        <!-- top bar -->
        <header class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="font-mono text-accent glow tracking-[0.3em] text-lg"><BrandMark /> toady</div>
            <nav class="flex items-center gap-4 text-[11px] font-mono text-ink-faint">
                <Link href="/beta" class="text-sky-300 hover:text-sky-200">beta</Link>
                <Link v-if="$page.props.showcaseEnabled" href="/showcase" class="hover:text-accent hidden sm:inline">showcase</Link>
                <Link href="/guide" class="hover:text-accent hidden sm:inline">guide</Link>
                <Link href="/login" class="hover:text-accent">sign in</Link>
            </nav>
        </header>

        <!-- HERO -->
        <section class="max-w-6xl mx-auto px-4 pt-8 pb-20 grid lg:grid-cols-2 gap-10 items-center">
            <div>
                <div class="flex items-center gap-2 text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-4">
                    <span class="w-6 h-px bg-accent/40"></span> Mission command
                    <Link href="/beta" class="text-sky-300 border border-sky-400/40 bg-sky-400/10 rounded px-1.5 py-0.5 hover:border-sky-300">Open beta ›</Link>
                </div>
                <h1 class="font-mono font-semibold text-4xl sm:text-5xl leading-[1.05] tracking-tight">
                    <span class="text-ink">Plan the fields.</span><br>
                    <span class="text-ink">Share the link.</span><br>
                    <span class="text-accent glow">Run it live.</span>
                </h1>
                <p class="mt-5 text-ink-dim max-w-md leading-relaxed">
                    Multiplayer mission command for Ingress. Spin up an op, drop the join link, and run the
                    whole field op on one live scanner with your team. <span class="text-ink">Ephemeral by design</span> — close the op and it’s gone.
                </p>
                <div class="mt-7 max-w-xs">
                    <GoogleButton label="Sign in — spin up an op" />
                    <div class="flex gap-2 mt-2">
                        <Link href="/register" class="flex-1 text-center border border-line rounded py-2 text-sm font-mono text-ink hover:border-accent hover:text-accent">Create account</Link>
                        <Link href="/login" class="flex-1 text-center border border-line rounded py-2 text-sm font-mono text-ink hover:border-accent hover:text-accent">Sign in</Link>
                    </div>
                </div>
                <div class="mt-6 flex flex-wrap items-center gap-3 text-[11px] font-mono text-ink-faint">
                    <span class="text-enl border border-enl/40 rounded px-1.5 py-0.5">ENL</span>
                    <span class="text-res border border-res/40 rounded px-1.5 py-0.5">RES</span>
                    <span>both factions · no app to install · runs on your phone</span>
                </div>
            </div>
            <!-- scanner panel -->
            <div class="relative">
                <div class="rounded-xl border border-emerald-500/20 bg-surface/60 backdrop-blur-sm p-3 shadow-[0_0_60px_-20px_rgba(28,240,160,0.4)]">
                    <div class="flex items-center justify-between text-[10px] font-mono uppercase tracking-wider text-ink-faint mb-1 px-1">
                        <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span> live op</span>
                        <span>◢ 4 fields · 9 links · 3 agents</span>
                    </div>
                    <div class="aspect-[4/3]"><HeroScanner /></div>
                </div>
            </div>
        </section>

        <!-- PROBLEM -->
        <section class="border-y border-line/60 bg-surface/30">
            <div class="max-w-6xl mx-auto px-4 py-14 reveal">
                <h2 class="font-mono text-2xl sm:text-3xl text-ink tracking-tight">Group chats don’t run ops.</h2>
                <p class="mt-3 text-ink-dim max-w-2xl leading-relaxed">
                    Links buried in the scroll. Nobody’s sure who has keys. Two agents farming the same anchor.
                    The plan lives in three apps and somebody’s screenshot. toady puts the whole op in one war-room —
                    and wipes it when you’re done.
                </p>
            </div>
        </section>

        <!-- HOW IT WORKS -->
        <section class="max-w-6xl mx-auto px-4 py-16">
            <div class="text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-8 reveal">How it works</div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="s in steps" :key="s.n" class="reveal border border-line rounded-lg bg-surface p-4">
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-mono text-accent/40 text-lg">{{ s.n }}</span>
                        <component :is="s.icon" :size="20" class="text-accent" />
                    </div>
                    <h3 class="font-mono text-ink text-sm uppercase tracking-wide">{{ s.t }}</h3>
                    <p class="mt-1.5 text-sm text-ink-dim leading-relaxed">{{ s.b }}</p>
                </div>
            </div>
        </section>

        <!-- LIVE MOCK STRIP -->
        <section class="border-y border-line/60 bg-surface/30">
            <div class="max-w-6xl mx-auto px-4 py-16 reveal">
                <div class="text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-6">The war-room</div>
                <div class="grid md:grid-cols-3 gap-3">
                    <!-- map tile -->
                    <div class="border border-line rounded-lg bg-surface overflow-hidden">
                        <div class="bg-emerald-500/5 border-b border-line px-2 py-1.5 text-[11px] font-mono uppercase tracking-wide text-ink-dim flex items-center gap-1.5"><Map :size="13" /> Map</div>
                        <div class="aspect-video"><HeroScanner :delay="1.3" /></div>
                    </div>
                    <!-- plan tile — directives + keys, together (the Plan panel) -->
                    <div class="border border-line rounded-lg bg-surface overflow-hidden">
                        <div class="bg-emerald-500/5 border-b border-line px-2 py-1.5 text-[11px] font-mono uppercase tracking-wide text-ink-dim flex items-center gap-1.5"><ClipboardList :size="13" /> Plan</div>
                        <ul class="p-2 space-y-1.5 text-sm">
                            <li v-for="(d, i) in [['hack', 'Anchor — full deploy', true], ['link', '→ Sapelo Island', true], ['field', 'Throw the south field', false]]" :key="i" class="flex items-center gap-2">
                                <span class="w-3.5 h-3.5 rounded-sm border flex items-center justify-center shrink-0" :class="d[2] ? 'bg-accent border-accent' : 'border-line'"><Check v-if="d[2]" :size="10" class="text-accent-ink" /></span>
                                <span class="text-[9px] font-mono uppercase text-accent/70 shrink-0">{{ d[0] }}</span>
                                <span class="truncate" :class="d[2] ? 'text-ink-faint line-through' : 'text-ink-dim'">{{ d[1] }}</span>
                            </li>
                        </ul>
                        <div class="border-t border-line/60 px-2 py-1.5 text-sm font-mono">
                            <div class="flex items-center gap-1.5 text-[9px] uppercase tracking-wide text-ink-faint mb-1"><KeyRound :size="11" /> keys held</div>
                            <div v-for="(k, i) in [['Sapelo Island', '8/8', true], ['Dover Bluff', '5/6', false]]" :key="i" class="flex items-center gap-2">
                                <span class="truncate text-ink-dim flex-1">{{ k[0] }}</span>
                                <span :class="k[2] ? 'text-accent' : 'text-rose-400'">{{ k[1] }}</span>
                            </div>
                        </div>
                    </div>
                    <!-- comms tile -->
                    <div class="border border-line rounded-lg bg-surface overflow-hidden">
                        <div class="bg-emerald-500/5 border-b border-line px-2 py-1.5 text-[11px] font-mono uppercase tracking-wide text-ink-dim flex items-center gap-1.5"><MessagesSquare :size="13" /> Comms</div>
                        <ul class="p-2 space-y-2 text-sm">
                            <li class="min-w-0">
                                <span class="text-[9px] font-mono uppercase text-accent/70">Vireo</span>
                                <p class="truncate text-ink-dim">on the anchor — throwing the S field</p>
                            </li>
                            <li class="min-w-0">
                                <span class="text-[9px] font-mono uppercase text-accent/70">Kestrel</span>
                                <p class="truncate text-ink-dim"><span class="text-accent">@Rook</span> need 2 keys for Dover</p>
                            </li>
                            <li class="min-w-0">
                                <span class="text-[9px] font-mono uppercase text-accent/70">Rook</span>
                                <p class="truncate text-ink-dim">on it — 5 min out</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- FEATURES -->
        <section class="max-w-6xl mx-auto px-4 py-16">
            <div class="text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-8 reveal">Loadout</div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-px bg-line/60 border border-line/60 rounded-lg overflow-hidden">
                <div v-for="f in features" :key="f.t" class="reveal bg-bg p-5">
                    <component :is="f.icon" :size="20" class="text-accent mb-2.5" />
                    <h3 class="font-mono text-ink text-sm">{{ f.t }}</h3>
                    <p class="mt-1 text-sm text-ink-dim leading-relaxed">{{ f.b }}</p>
                </div>
            </div>
        </section>

        <!-- EPHEMERAL -->
        <section class="border-y border-line/60 bg-surface/30">
            <div class="max-w-6xl mx-auto px-4 py-16 reveal flex flex-col items-start gap-4">
                <ShieldOff :size="28" class="text-accent" :stroke-width="1.5" />
                <h2 class="font-mono text-2xl sm:text-3xl text-ink tracking-tight">Built to disappear.</h2>
                <p class="text-ink-dim max-w-2xl leading-relaxed">
                    We keep nothing. No op history, no agent tracking, no analytics following you around. Close the op
                    and every participant, message, and waypoint is purged — by default. Want to keep your callsign and
                    a few plan templates? That’s opt-in, tied to your own sign-in. Nothing else sticks around.
                </p>
                <Link href="/privacy" class="text-sm font-mono text-accent hover:underline inline-flex items-center gap-1">read the privacy story <ArrowRight :size="14" /></Link>
            </div>
        </section>

        <!-- FINAL CTA -->
        <section class="max-w-6xl mx-auto px-4 py-24 text-center reveal">
            <h2 class="font-mono text-3xl sm:text-4xl text-accent glow tracking-tight">Your next op starts here.</h2>
            <p class="mt-3 text-ink-dim">Free. No install. Sign in and spin one up.</p>
            <div class="mt-7 max-w-xs mx-auto"><GoogleButton label="Sign in with Google" /></div>
        </section>

        <!-- FOOTER -->
        <footer class="border-t border-line/60">
            <div class="max-w-6xl mx-auto px-4 py-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px] font-mono text-ink-faint">
                <div class="text-accent/70 tracking-[0.3em]"><BrandMark :glow="false" size="1em" /> toady</div>
                <nav class="flex items-center gap-4">
                    <Link href="/beta" class="text-sky-300 hover:text-sky-200">beta</Link>
                    <Link v-if="$page.props.showcaseEnabled" href="/showcase" class="hover:text-accent">showcase</Link>
                    <Link href="/guide" class="hover:text-accent">guide</Link>
                    <Link href="/donate" class="hover:text-accent">support</Link>
                    <Link href="/privacy" class="hover:text-accent">privacy</Link>
                    <Link href="/terms" class="hover:text-accent">terms</Link>
                </nav>
                <p class="text-center sm:text-right opacity-70">Not affiliated with Niantic. Ingress is a trademark of Niantic, Inc.</p>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.reveal { opacity: 0; transform: translateY(14px); transition: opacity 0.6s ease, transform 0.6s ease; }
.reveal.in { opacity: 1; transform: none; }
@media (prefers-reduced-motion: reduce) {
    .reveal { opacity: 1; transform: none; transition: none; }
}
</style>
