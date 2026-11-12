<script setup>
import { inject, ref, computed, watch, onBeforeUnmount } from 'vue';
import { CloudLightning, Snowflake, CloudRain, CloudFog, CloudSun, Cloud, Sun, Sunrise, Sunset } from 'lucide-vue-next';
import { usePoll } from '@/useLive';
import WeatherRadar from '@/Components/WeatherRadar.vue';

const c = inject('opctx');
const wx = ref(null);
const loading = ref(true);

// the radar centres on the op's first placed waypoint (same area the forecast is for)
const center = computed(() => {
    const w = (c.data.waypoints || []).find((x) => x.lat != null);
    return w ? { lat: w.lat, lng: w.lng } : null;
});

let retryTimer = null;
function scheduleRetry() {
    // a transient upstream hiccup shouldn't leave the panel blank for the full 10-min poll gap
    clearTimeout(retryTimer);
    retryTimer = setTimeout(load, 30000);
}
async function load() {
    try {
        const { data } = await window.axios.get(`/ops/${c.data.op.id}/weather`);
        wx.value = data;
        // region is known but the weather service was momentarily down → try again shortly
        if (data && data.ok === false && center.value) scheduleRetry();
    } catch (e) { scheduleRetry(); } finally { loading.value = false; }
}
// (re)load the moment a region becomes available (first waypoint placed) or the op's area shifts —
// no manual page refresh needed. Keyed on the coords so the 3s prop-poll doesn't re-fire this.
watch(() => (center.value ? `${center.value.lat},${center.value.lng}` : null), (now, prev) => {
    if (now && now !== prev) load();
});
onBeforeUnmount(() => clearTimeout(retryTimer));
function hour(t) { return new Date(t).toLocaleTimeString([], { hour: 'numeric' }); }
function clock(t) { return t ? new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'; }
function glyph(text = '') {
    const s = (text || '').toLowerCase();
    if (s.includes('thunder') || s.includes('tstorm') || s.includes('storm')) return CloudLightning;
    if (s.includes('snow') || s.includes('sleet') || s.includes('ice') || s.includes('flurr')) return Snowflake;
    if (s.includes('rain') || s.includes('shower') || s.includes('drizzle')) return CloudRain;
    if (s.includes('fog') || s.includes('haze') || s.includes('mist')) return CloudFog;
    if (s.includes('partly') || s.includes('mostly sunny') || s.includes('mostly clear')) return CloudSun;
    if (s.includes('cloud') || s.includes('overcast')) return Cloud;
    if (s.includes('clear') || s.includes('sunny') || s.includes('fair')) return Sun;
    return Cloud;
}

usePoll(load, 600000);
</script>

<template>
    <div class="h-full w-full flex flex-col gap-3 p-3 overflow-auto op-scroll">
        <!-- TOP: current conditions split 50/50 with the radar map; hourly + 7-day go full width below -->
        <div class="flex gap-3 flex-1 min-h-[10rem]">
            <!-- LEFT: NOW hero (container-type:size lets the hero scale with the row height via cqh) -->
            <div class="w-1/2 min-w-0 flex flex-col [container-type:size]">
                <p v-if="loading" class="m-auto text-ink-faint text-sm">reading conditions…</p>
                <div v-else-if="wx && wx.ok" class="flex-1 min-h-[4.5rem] flex items-center justify-center gap-4 flex-wrap content-center">
                    <component :is="glyph(wx.hourly?.[0]?.short)" class="shrink-0 text-accent w-[clamp(2.25rem,20cqh,7rem)] h-[clamp(2.25rem,20cqh,7rem)]" :stroke-width="1.5" />
                    <div class="min-w-0">
                        <div class="font-mono text-ink-faint uppercase tracking-wide truncate text-[clamp(0.65rem,2.6cqh,0.95rem)]">{{ wx.place || 'field' }}</div>
                        <div class="font-mono text-accent glow leading-none text-[clamp(1.75rem,14cqh,5.5rem)]">{{ wx.hourly?.[0]?.temp ?? '—' }}°<span class="text-[0.4em] align-top">{{ wx.hourly?.[0]?.unit }}</span></div>
                        <div class="text-ink-dim truncate text-[clamp(0.8rem,3cqh,1.25rem)]">{{ wx.hourly?.[0]?.short }}</div>
                        <div v-if="wx.hourly?.[0]?.wind" class="text-ink-faint truncate text-[clamp(0.7rem,2.4cqh,1rem)]">wind {{ wx.hourly[0].wind }}</div>
                        <div v-if="wx.sun" class="flex items-center gap-1 text-ink-faint font-mono mt-0.5 text-[clamp(0.65rem,2.4cqh,1rem)]"><Sunrise :size="13" class="shrink-0" /> {{ clock(wx.sun.sunrise) }} <span class="opacity-50">·</span> <Sunset :size="13" class="shrink-0" /> {{ clock(wx.sun.sunset) }}</div>
                    </div>
                </div>
                <p v-else class="m-auto text-center text-ink-faint text-sm">{{ wx?.error || 'Add waypoints to locate weather.' }}</p>
            </div>

            <!-- RIGHT: static radar map (centred on the op, regional zoom, radar overlay, sat toggle) -->
            <div class="w-1/2 min-w-0 rounded-lg overflow-hidden border border-line bg-inset">
                <WeatherRadar v-if="center" :lat="center.lat" :lng="center.lng" />
                <div v-else class="h-full flex items-center justify-center text-center text-ink-faint text-xs px-2">Add a placed waypoint to show the radar.</div>
            </div>
        </div>

        <!-- BELOW (full width): hourly + 7-day reports -->
        <template v-if="wx && wx.ok">
            <div v-if="wx.hourly?.length" class="shrink-0">
                <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Hourly</div>
                <div class="grid grid-cols-6 gap-1">
                    <div v-for="h in wx.hourly.slice(0, 6)" :key="h.time" class="flex flex-col items-center justify-center gap-0.5 py-1.5 text-center font-mono text-[11px] border border-line/40 rounded">
                        <div class="text-ink-faint">{{ hour(h.time) }}</div>
                        <component :is="glyph(h.short)" :size="20" class="text-ink-dim" />
                        <div class="text-ink">{{ h.temp }}°</div>
                        <div class="text-sky-400/70">{{ h.precip ?? 0 }}%</div>
                    </div>
                </div>
            </div>

            <div v-if="wx.daily?.length" class="shrink-0">
                <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">7-day</div>
                <div class="grid gap-1" :style="`grid-template-columns: repeat(${wx.daily.length}, minmax(0, 1fr))`">
                    <div v-for="d in wx.daily" :key="d.date" class="flex flex-col items-center justify-center gap-0.5 py-1.5 text-center font-mono text-[11px] border border-line/40 rounded">
                        <div class="text-ink-dim">{{ d.name }}</div>
                        <component :is="glyph(d.short)" :size="20" class="text-ink-dim" />
                        <div class="text-ink">{{ d.hi ?? '—' }}°</div>
                        <div class="text-ink-faint">{{ d.lo ?? '—' }}°</div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
