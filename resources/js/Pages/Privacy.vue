<script setup>
import DocLayout from '@/Layouts/DocLayout.vue';
</script>

<template>
    <DocLayout title="Privacy" tag="Plain language. No lawyers. Last updated June 22, 2026.">
        <p>
            toady is a tool for planning and running Ingress operations with a team. We collect only what's needed to
            do that, we don't sell or share it, and we show no ads or third-party trackers.
            <span class="text-ink">Not affiliated with or endorsed by Niantic.</span>
        </p>

        <section>
            <h2 class="hud-label font-mono text-sm uppercase tracking-wider text-ink-dim mb-2">What we store</h2>
            <ul class="space-y-2 list-none">
                <li><span class="text-accent">Your account.</span> A callsign and faction, plus the way you sign in — either a Google identity (email + basic profile) or an email address and a hashed password. Your sign-in email is used for login, verification, password resets, and any announcements you opt into; <span class="text-ink">it is never shown to other agents or op operators.</span> Only the site administrator can see registered emails — to manage accounts and send those announcements.</li>
                <li><span class="text-accent">Optional contact info.</span> Phone, Telegram, a preferred-contact note, and an emergency contact — all optional, and visible only to members of the same op so your team can reach you in the field.</li>
                <li><span class="text-accent">Profile photo.</span> Optional, shown to your op's roster. We re-encode it and strip its metadata, including any embedded GPS location.</li>
                <li><span class="text-accent">Op data.</span> Waypoints, directives, key counts, chat, and direct messages — visible to that op's members while the op is open.</li>
                <li><span class="text-accent">Location.</span> Off by default. You opt in per op; your position is shared only with that op and goes stale automatically when you stop.</li>
                <li><span class="text-accent">Notifications.</span> If you enable push, we store your device's push subscription so we can buzz you about assignments and messages. Revoke it any time.</li>
            </ul>
        </section>

        <section>
            <h2 class="hud-label font-mono text-sm uppercase tracking-wider text-ink-dim mb-2">What gets deleted</h2>
            <p>
                When an Operator <span class="text-accent">closes an op, all of its data is permanently purged</span> —
                waypoints, directives, keys, chat, DMs, and shared locations. Export the plan first if you want to keep it.
                You can edit or delete your <span class="text-ink">profile and entire account</span> at any time from your
                profile page; deleting your account removes your personal data.
            </p>
        </section>

        <section>
            <h2 class="hud-label font-mono text-sm uppercase tracking-wider text-ink-dim mb-2">Who else sees it</h2>
            <p>
                Sign-in uses <span class="text-ink">Google</span> solely to verify identity — we never post or read
                anything from your account. Verification, password-reset, and the occasional toady announcement email are
                sent through our email provider; you can opt out of announcements any time from your profile or the
                unsubscribe link in any such email.
                Loading the map sends ordinary map-tile and route requests to map services (basemap, satellite, weather
                radar, and — if you turn it on — traffic and walking directions); those services see the map area you're
                viewing, not your toady account. Our public pages use <span class="text-ink">Google Analytics</span> for
                basic visit statistics, but it's turned off once you sign in — <span class="text-ink">no analytics follow
                you inside the app</span> — and we use no advertising trackers.
            </p>
        </section>

        <section>
            <h2 class="hud-label font-mono text-sm uppercase tracking-wider text-ink-dim mb-2">No backdoor · fair play</h2>
            <p>
                The app shows an op <span class="text-ink">only to the agents in it.</span> There is no administrator
                override: site owners and admins have <span class="text-ink">no way to view, join, or read an op they
                aren't part of.</span> toady gives <span class="text-ink">no faction any advantage</span> — Enlightened
                and Resistance teams are equally welcome, with no cheating and no edge for either side.
            </p>
        </section>

        <section>
            <h2 class="hud-label font-mono text-sm uppercase tracking-wider text-ink-dim mb-2">Contact</h2>
            <p>Questions or a deletion request? <a href="mailto:contact@toady.net" class="text-accent hover:underline">contact@toady.net</a>.</p>
        </section>
    </DocLayout>
</template>
