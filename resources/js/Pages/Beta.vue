<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GoogleButton from '@/Components/GoogleButton.vue';
import BrandMark from '@/Components/BrandMark.vue';
import {
    FlaskConical, Target, Share2, Radio, Trash2, ShieldOff, EyeOff, Bot,
    Users, Waypoints, Bug, Award, ArrowRight, Check, Send, Github,
} from 'lucide-vue-next';

const REPO_URL = 'https://github.com/rocketskatesninja/toady';

defineProps({ canLogin: { type: Boolean, default: true } });

// what a beta test run actually looks like
const ask = [
    { n: '01', icon: Target, t: 'Spin up a real op', b: 'Not a toy plan — the next field op you were going to run anyway. Pull portals from the catalog or paste an IITC export and auto-build the fan.' },
    { n: '02', icon: Share2, t: 'Pull your team in', b: 'Drop the join link in your comms. See how fast agents get on the same scanner — and tell us where it snags.' },
    { n: '03', icon: Radio, t: 'Run it live', b: 'Directives, key locker, live map, comms — under real field conditions, on real phones, with real signal.' },
    { n: '04', icon: Trash2, t: 'Stand down', b: 'Close the op and watch it purge. Then hit the feedback button and tell us what felt off, slow, or missing.' },
];

// the trust story — the part that actually decides whether agents will touch it
const trust = [
    { icon: ShieldOff, t: 'No Intel scraping. Ever.', b: 'toady never touches Niantic’s Intel API. Portal data comes from your own IITC export or the community catalog — nothing is pulled from Niantic behind the scenes.' },
    { icon: Trash2, t: 'Ephemeral by design', b: 'Close the op and every participant, message, waypoint, and key report is purged. No op history sits on our servers waiting to leak.' },
    { icon: EyeOff, t: 'We don’t track agents', b: 'No location history, no behavioural profiling, no analytics following you around the app once you sign in. Live position exists only inside a running op, only for that op’s team.' },
    { icon: Bot, t: 'Bring your own AI key', b: 'The concierge runs on your key. It never leaves your browser by default; turn on cross-device sync and it’s stored encrypted, never in the clear. Turn the AI off entirely and everything else still works.' },
    { icon: Users, t: 'Both factions, no games', b: 'ENL and RES, same tool, same terms. It’s a war-room, not an enemy scanner — nothing here deanonymizes anyone.' },
    { icon: Waypoints, t: 'Your data stays yours', b: 'Keep a callsign and a few plan templates if you opt in — tied to your own sign-in. Nothing else sticks around after the op is gone.' },
];

// lightweight scroll-reveal (mirrors the landing page)
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
    <Head title="toady — open beta" />
    <div ref="root" class="min-h-screen text-ink font-sans">
        <!-- top bar -->
        <header class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <Link href="/" class="font-mono text-accent glow tracking-[0.3em] text-lg"><BrandMark /> toady</Link>
            <nav class="flex items-center gap-4 text-[11px] font-mono text-ink-faint">
                <Link v-if="$page.props.showcaseEnabled" href="/showcase" class="hover:text-accent hidden sm:inline">showcase</Link>
                <Link href="/guide" class="hover:text-accent hidden sm:inline">guide</Link>
                <Link href="/login" class="hover:text-accent">sign in</Link>
            </nav>
        </header>

        <!-- HERO -->
        <section class="max-w-5xl mx-auto px-4 pt-10 pb-16">
            <div class="inline-flex items-center gap-2 text-[10px] font-mono uppercase tracking-[0.25em] text-sky-300 border border-sky-400/40 bg-sky-400/10 rounded px-2 py-1 mb-6">
                <FlaskConical :size="13" /> Open beta · recruiting operatives
            </div>
            <h1 class="font-mono font-semibold text-4xl sm:text-5xl leading-[1.05] tracking-tight max-w-3xl">
                <span class="text-ink">Run with us while</span><br>
                <span class="text-accent glow">it’s still being built.</span>
            </h1>
            <p class="mt-5 text-ink-dim max-w-xl leading-relaxed">
                toady is multiplayer mission command for Ingress — spin up an op, share one link, and run the whole
                field op on a single live scanner with your team. It works today, and it’s in
                <span class="text-ink">open beta</span>: we’re looking for agents to run real ops on it and tell us
                where it bends. Get in early and help shape v1.
            </p>
            <div class="mt-7 max-w-xs">
                <GoogleButton label="Sign in — join the beta" />
                <div class="flex gap-2 mt-2">
                    <Link href="/register" class="flex-1 text-center border border-line rounded py-2 text-sm font-mono text-ink hover:border-accent hover:text-accent">Create account</Link>
                    <Link href="/login" class="flex-1 text-center border border-line rounded py-2 text-sm font-mono text-ink hover:border-accent hover:text-accent">Sign in</Link>
                </div>
            </div>
            <div class="mt-6 flex flex-wrap items-center gap-3 text-[11px] font-mono text-ink-faint">
                <span class="text-enl border border-enl/40 rounded px-1.5 py-0.5">ENL</span>
                <span class="text-res border border-res/40 rounded px-1.5 py-0.5">RES</span>
                <span>both factions · no app to install · free during beta and after</span>
            </div>
        </section>

        <!-- THE ASK -->
        <section class="border-y border-line/60 bg-surface/30">
            <div class="max-w-5xl mx-auto px-4 py-16">
                <div class="text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-2 reveal">What we’re asking</div>
                <h2 class="font-mono text-2xl sm:text-3xl text-ink tracking-tight reveal">Run one real op on it.</h2>
                <p class="mt-3 text-ink-dim max-w-2xl leading-relaxed reveal">
                    The best test is the op you were already going to run. Take it through toady end to end — then
                    tell us where it slowed you down. That feedback is the whole point of the beta.
                </p>
                <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="s in ask" :key="s.n" class="reveal border border-line rounded-lg bg-surface p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-mono text-accent/40 text-lg">{{ s.n }}</span>
                            <component :is="s.icon" :size="20" class="text-accent" />
                        </div>
                        <h3 class="font-mono text-ink text-sm uppercase tracking-wide">{{ s.t }}</h3>
                        <p class="mt-1.5 text-sm text-ink-dim leading-relaxed">{{ s.b }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- TRUST / OPSEC -->
        <section class="max-w-5xl mx-auto px-4 py-16">
            <div class="text-[10px] font-mono uppercase tracking-[0.25em] text-accent/60 mb-2 reveal">Before you sign in</div>
            <h2 class="font-mono text-2xl sm:text-3xl text-ink tracking-tight reveal">Built to respect your OPSEC.</h2>
            <p class="mt-3 text-ink-dim max-w-2xl leading-relaxed reveal">
                You’ve been burned by third-party tools before. So have we. Here’s exactly how toady treats your
                data and Niantic’s terms — no fine print.
            </p>
            <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-px bg-line/60 border border-line/60 rounded-lg overflow-hidden">
                <div v-for="c in trust" :key="c.t" class="reveal bg-bg p-5">
                    <div class="flex items-center gap-2.5">
                        <component :is="c.icon" :size="20" class="text-accent shrink-0" :stroke-width="1.75" />
                        <h3 class="font-mono text-ink text-sm">{{ c.t }}</h3>
                    </div>
                    <p class="mt-1.5 text-sm text-ink-dim leading-relaxed">{{ c.b }}</p>
                </div>
            </div>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm font-mono reveal">
                <Link href="/privacy" class="text-accent hover:underline inline-flex items-center gap-1">the full privacy story <ArrowRight :size="14" /></Link>
                <Link href="/terms" class="text-ink-faint hover:text-accent">terms</Link>
            </div>
        </section>

        <!-- FOUNDING OPERATIVE -->
        <section class="border-y border-line/60 bg-surface/30">
            <div class="max-w-5xl mx-auto px-4 py-16 reveal flex flex-col items-start gap-4">
                <div class="flex items-center gap-3">
                    <Award :size="28" class="text-accent shrink-0" :stroke-width="1.5" />
                    <h2 class="font-mono text-2xl sm:text-3xl text-ink tracking-tight">Founding Operatives.</h2>
                </div>
                <p class="text-ink-dim max-w-2xl leading-relaxed">
                    Sign in during the beta and you’re a Founding Operative — the agents who shaped the thing before
                    v1. Your reports go straight to the people building it, the features you push for are the ones
                    that get built, and it stays <span class="text-ink">free for you, always</span>. No paywall waiting
                    at the end of the runway.
                </p>
                <ul class="grid sm:grid-cols-2 gap-x-8 gap-y-2 text-sm text-ink-dim">
                    <li class="flex items-start gap-2"><Check :size="16" class="text-accent shrink-0 mt-0.5" /> Direct line to the builders</li>
                    <li class="flex items-start gap-2"><Check :size="16" class="text-accent shrink-0 mt-0.5" /> Your feedback steers v1</li>
                    <li class="flex items-start gap-2"><Check :size="16" class="text-accent shrink-0 mt-0.5" /> Free during beta and after</li>
                    <li class="flex items-start gap-2"><Check :size="16" class="text-accent shrink-0 mt-0.5" /> Both factions welcome</li>
                </ul>
            </div>
        </section>

        <!-- FEEDBACK / EXPECTATIONS -->
        <section class="max-w-5xl mx-auto px-4 py-16">
            <div class="grid md:grid-cols-2 gap-8 items-start">
                <div class="reveal">
                    <div class="flex items-center gap-2.5 mb-3">
                        <Bug :size="24" class="text-accent shrink-0" :stroke-width="1.75" />
                        <h2 class="font-mono text-xl sm:text-2xl text-ink tracking-tight">Feedback is one tap away.</h2>
                    </div>
                    <p class="mt-3 text-ink-dim leading-relaxed">
                        Once you’re in, the <span class="text-sky-300 font-mono">BETA</span> badge up top opens a report
                        box from anywhere in the app — say what went wrong, drop a screenshot, done. It lands with the
                        team instantly. No forum, no ticket queue.
                    </p>
                </div>
                <div class="reveal border border-line rounded-lg bg-surface p-5">
                    <h3 class="font-mono text-sm uppercase tracking-wide text-ink-faint mb-3">What “beta” means here</h3>
                    <ul class="space-y-2.5 text-sm text-ink-dim">
                        <li class="flex items-start gap-2"><span class="text-accent font-mono">›</span> Expect rough edges — that’s what you’re here to find.</li>
                        <li class="flex items-start gap-2"><span class="text-accent font-mono">›</span> We may reset data or ship changes mid-beta.</li>
                        <li class="flex items-start gap-2"><span class="text-accent font-mono">›</span> The ephemeral promise still holds: close an op, it’s gone.</li>
                        <li class="flex items-start gap-2"><span class="text-accent font-mono">›</span> No pressure, no lock-in — run one op and tell us how it went.</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- OPEN SOURCE -->
        <section class="max-w-5xl mx-auto px-4 pb-8">
            <div class="reveal border border-line rounded-lg bg-surface p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center gap-5 justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5">
                        <Github :size="22" class="text-accent shrink-0" :stroke-width="1.75" />
                        <h2 class="font-mono text-lg text-ink tracking-tight">Built in the open.</h2>
                    </div>
                    <p class="mt-1.5 text-sm text-ink-dim leading-relaxed max-w-xl">
                        toady is open source — Laravel, Vue, and Inertia. Read the code to see how the ephemeral
                        and no-Intel-scraping promises are actually enforced, file an issue, or self-host your own.
                    </p>
                </div>
                <div class="shrink-0 w-full sm:w-auto">
                    <a :href="REPO_URL" target="_blank" rel="noopener noreferrer"
                        class="inline-flex items-center gap-2.5 rounded-lg border border-accent/50 bg-accent/10 px-4 py-2.5 font-mono text-sm text-accent hover:bg-accent/20">
                        <Github :size="16" class="shrink-0" />
                        <span class="flex-1 whitespace-nowrap">View on GitHub</span>
                        <ArrowRight :size="14" class="shrink-0" />
                    </a>
                </div>
            </div>
        </section>

        <!-- COMMUNITY / TELEGRAM -->
        <section class="max-w-5xl mx-auto px-4 pb-8">
            <div class="reveal border border-sky-400/30 bg-sky-400/5 rounded-lg p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center gap-5 justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5">
                        <Send :size="22" class="text-sky-300 shrink-0" :stroke-width="1.75" />
                        <h2 class="font-mono text-lg text-ink tracking-tight">Join the comms.</h2>
                    </div>
                    <p class="mt-1.5 text-sm text-ink-dim leading-relaxed max-w-xl">
                        The channel carries beta news and features as they ship; the group is where you talk shop
                        with other operatives running toady — both factions welcome. Found a bug? Use the in-app
                        report button; Telegram’s for everything else.
                    </p>
                </div>
                <div class="shrink-0 flex flex-col gap-2 w-full sm:w-auto">
                    <a href="https://t.me/toadynet" target="_blank" rel="noopener noreferrer"
                        class="inline-flex items-center gap-2.5 rounded-lg border border-sky-400/50 bg-sky-400/10 px-4 py-2.5 font-mono text-sm text-sky-200 hover:bg-sky-400/20">
                        <Radio :size="16" class="shrink-0" />
                        <span class="flex-1 whitespace-nowrap">Channel · <span class="text-sky-100">@toadynet</span></span>
                        <span class="text-[10px] uppercase tracking-wide text-sky-300/70">news</span>
                    </a>
                    <a href="https://t.me/toadynetgroup" target="_blank" rel="noopener noreferrer"
                        class="inline-flex items-center gap-2.5 rounded-lg border border-sky-400/50 bg-sky-400/10 px-4 py-2.5 font-mono text-sm text-sky-200 hover:bg-sky-400/20">
                        <Users :size="16" class="shrink-0" />
                        <span class="flex-1 whitespace-nowrap">Group · <span class="text-sky-100">@toadynetgroup</span></span>
                        <span class="text-[10px] uppercase tracking-wide text-sky-300/70">chat</span>
                    </a>
                </div>
            </div>
        </section>

        <!-- FINAL CTA -->
        <section class="max-w-5xl mx-auto px-4 py-24 text-center reveal">
            <h2 class="font-mono text-3xl sm:text-4xl text-accent glow tracking-tight">Join the beta.</h2>
            <p class="mt-3 text-ink-dim">Free. No install. Sign in and run your next op with us.</p>
            <div class="mt-7 max-w-xs mx-auto"><GoogleButton label="Sign in with Google" /></div>
            <div class="mt-3 text-[11px] font-mono text-ink-faint">
                or <Link href="/register" class="text-accent hover:underline">create an account</Link>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="border-t border-line/60">
            <div class="max-w-5xl mx-auto px-4 py-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px] font-mono text-ink-faint">
                <div class="text-accent/70 tracking-[0.3em]"><BrandMark :glow="false" size="1em" /> toady</div>
                <nav class="flex items-center gap-4">
                    <a href="https://t.me/toadynet" target="_blank" rel="noopener noreferrer" class="text-sky-300 hover:text-sky-200">channel</a>
                    <a href="https://t.me/toadynetgroup" target="_blank" rel="noopener noreferrer" class="text-sky-300 hover:text-sky-200">group</a>
                    <Link href="/" class="hover:text-accent">home</Link>
                    <Link v-if="$page.props.showcaseEnabled" href="/showcase" class="hover:text-accent">showcase</Link>
                    <Link href="/guide" class="hover:text-accent">guide</Link>
                    <a :href="REPO_URL" target="_blank" rel="noopener noreferrer" class="hover:text-accent">source</a>
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
