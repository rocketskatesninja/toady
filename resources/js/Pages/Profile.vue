<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({ profile: Object });

const form = useForm({
    faction: props.profile.faction,
    phone: props.profile.phone,
    telegram: props.profile.telegram,
    preferred_contact: props.profile.preferred_contact,
    emergency_contact: props.profile.emergency_contact,
    email_opt_out: props.profile.email_opt_out,
});
function save() { form.put('/profile', { preserveScroll: true }); }

// Restore every dismissed "don't ask again" prompt (this browser only): the confirmAction skip-flags
// (toady:skip:* — remove location/directive, flag a name, …) plus the map's add-a-portal confirm
// (toady-catalog-skip). Enumerate localStorage so new prompt keys are covered automatically.
const promptsFlash = ref(false);
function resetPrompts() {
    try {
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const k = localStorage.key(i);
            if (k && (k.startsWith('toady:skip:') || k === 'toady-catalog-skip')) localStorage.removeItem(k);
        }
    } catch (e) { /* storage unavailable */ }
    promptsFlash.value = true;
    setTimeout(() => { promptsFlash.value = false; }, 2000);
}

// codename change (e.g. after a Niantic rename) — only when not in any op
const callsignForm = useForm({ callsign: props.profile.callsign });
function changeCallsign() { callsignForm.put('/profile/callsign', { preserveScroll: true }); }

// profile photo (multipart upload; server re-encodes to a 256px square)
const fileInput = ref(null);
const uploading = ref(false);
function pickPhoto() { fileInput.value?.click(); }
function onPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    uploading.value = true;
    router.post('/profile/avatar', { avatar: file }, {
        forceFormData: true, preserveScroll: true,
        onFinish: () => { uploading.value = false; if (fileInput.value) fileInput.value.value = ''; },
    });
}
function removePhoto() { router.delete('/profile/avatar', { preserveScroll: true }); }

// --- BYOK AI concierge: provider / key / model. Same store the in-op AI widget reads:
//     the key lives in this browser (localStorage 'toady-ai'); turning on sync also keeps an
//     encrypted copy on the account so it follows you across devices.
const AI_LS = 'toady-ai';
let aiSaved = {};
try { aiSaved = JSON.parse(localStorage.getItem(AI_LS) || '{}'); } catch (e) { /* */ }
const aiSrc = props.profile.ai_config || aiSaved; // synced config wins, else this device's saved one

const aiProvider = ref(aiSrc.provider || 'anthropic');
const aiKey = ref(aiSrc.key || '');
const aiModel = ref(aiSrc.model || '');
const aiSync = ref(!!props.profile.ai_config);
const aiModels = ref(aiModel.value ? [aiModel.value] : []); // seed so the saved model shows before the list loads
const aiLoadingModels = ref(false);
const aiModelsError = ref('');
const aiFlash = ref(false);

async function aiLoadModels() {
    aiModelsError.value = '';
    if (!aiKey.value.trim()) { aiModels.value = []; return; }
    aiLoadingModels.value = true;
    try {
        const { data } = await window.axios.post('/ai/models', { provider: aiProvider.value, key: aiKey.value.trim() });
        aiModels.value = data.models || [];
        if (!aiModel.value || !aiModels.value.includes(aiModel.value)) aiModel.value = aiModels.value[0] || '';
    } catch (e) {
        aiModelsError.value = e.response?.data?.error || 'Could not load models — check the key.';
        aiModels.value = aiModel.value ? [aiModel.value] : [];
    } finally {
        aiLoadingModels.value = false;
    }
}
function aiSwitchProvider(p) {
    if (aiProvider.value === p) return;
    aiProvider.value = p;
    aiModel.value = '';
    aiModels.value = [];
    aiLoadModels();
}
function aiSave() {
    const cfg = { provider: aiProvider.value, key: aiKey.value.trim(), model: aiModel.value };
    try { localStorage.setItem(AI_LS, JSON.stringify(cfg)); } catch (e) { /* */ }
    if (aiSync.value && cfg.key) window.axios.put('/profile/ai-config', cfg).catch(() => {});
    else window.axios.delete('/profile/ai-config').catch(() => {});
    aiFlash.value = true;
    setTimeout(() => (aiFlash.value = false), 2000);
}
function aiClear() {
    aiKey.value = ''; aiModel.value = ''; aiModels.value = []; aiSync.value = false;
    try { localStorage.removeItem(AI_LS); } catch (e) { /* */ }
    window.axios.delete('/profile/ai-config').catch(() => {});
}
const aiReady = computed(() => !!(aiKey.value.trim() && aiModel.value));
onMounted(() => { if (aiKey.value.trim()) aiLoadModels(); });

const confirming = ref(false);
const typed = ref('');
function del() { if (typed.value.trim().toUpperCase() === 'DELETE') router.delete('/profile'); }
</script>

<template>
    <Head title="Profile" />
    <AppLayout>
        <template #title>
            <span class="font-mono text-accent glow tracking-wide truncate">Profile · {{ profile.callsign }}</span>
        </template>


        <!-- codename — changeable only when you're not in any op (it's referenced across all of them) -->
        <form @submit.prevent="changeCallsign" class="border border-line rounded-lg bg-surface overflow-hidden mb-6 max-w-lg">
            <div class="px-1.5 py-2.5 border-b border-line hud-label text-xs font-mono text-ink-dim uppercase tracking-wide">Codename</div>
            <div class="px-1.5 py-3 space-y-2">
                <template v-if="profile.in_ops">
                    <span class="text-lg font-mono text-accent glow">{{ profile.callsign }}</span>
                    <p class="text-[11px] font-mono text-amber-300/80">Locked while you're in an op — leave or close all of your ops to change it (your codename is referenced across every op you're on).</p>
                </template>
                <template v-else>
                    <p class="text-[11px] font-mono text-ink-faint">Renamed in Ingress? Update it here — 3–15 letters and numbers.</p>
                    <div class="flex gap-2">
                        <input v-model="callsignForm.callsign" maxlength="15" spellcheck="false" placeholder="codename"
                            class="flex-1 min-w-0 bg-inset border border-line rounded px-1.5 py-1.5 text-sm font-mono focus:border-accent focus:outline-none" />
                        <button type="submit" :disabled="callsignForm.processing || !callsignForm.callsign.trim() || callsignForm.callsign === profile.callsign"
                            class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 disabled:opacity-50">Change</button>
                    </div>
                    <p v-if="callsignForm.errors.callsign" class="text-[11px] font-mono text-rose-400">{{ callsignForm.errors.callsign }}</p>
                </template>
            </div>
        </form>

        <form @submit.prevent="save" class="border border-line rounded-lg bg-surface overflow-hidden mb-6 max-w-lg">
            <div class="px-1.5 py-2.5 border-b border-line hud-label text-xs font-mono text-ink-dim uppercase tracking-wide">Identity</div>
            <div class="px-1.5 py-3 space-y-3">
                <!-- profile photo -->
                <div class="flex items-center gap-3">
                    <Avatar :src="profile.avatar" :callsign="profile.callsign" :faction="form.faction" :size="64" />
                    <div class="flex flex-col gap-1.5 min-w-0">
                        <div class="flex items-center gap-2">
                            <button type="button" @click="pickPhoto" :disabled="uploading" class="border border-line hover:border-accent text-ink-dim hover:text-accent font-mono text-xs rounded px-2 py-1.5 disabled:opacity-50">{{ uploading ? 'uploading…' : (profile.avatar ? 'change photo' : 'upload photo') }}</button>
                            <button v-if="profile.avatar" type="button" @click="removePhoto" class="text-ink-faint hover:text-rose-400 font-mono text-xs px-1.5">remove</button>
                        </div>
                        <p class="text-[10px] font-mono text-ink-faint">jpg / png / webp · shown in the roster</p>
                    </div>
                    <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPhoto" />
                </div>

                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-faint font-mono text-xs">email</span><span class="text-ink break-all">{{ profile.email }}</span>
                </div>
                <!-- broadcast-email opt in/out (the inverse of email_opt_out) -->
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <span class="text-xs font-mono text-ink-dim">Broadcast emails</span>
                        <p class="text-[10px] font-mono text-ink-faint mt-0.5">occasional announcements from the toady team — op &amp; in-app alerts are unaffected</p>
                    </div>
                    <button type="button" role="switch" :aria-checked="!form.email_opt_out" @click="form.email_opt_out = !form.email_opt_out"
                        :class="!form.email_opt_out ? 'bg-emerald-500/30 border-accent' : 'bg-inset border-line'"
                        class="relative shrink-0 w-11 h-6 rounded-full border transition-colors">
                        <span :class="!form.email_opt_out ? 'translate-x-5 bg-accent' : 'translate-x-0 bg-ink-faint'"
                            class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full transition-transform"></span>
                    </button>
                </div>
                <div>
                    <label class="block text-xs font-mono text-ink-dim mb-1">FACTION</label>
                    <div class="flex gap-2">
                        <button type="button" @click="form.faction = 'ENL'" :class="form.faction === 'ENL' ? 'border-accent bg-emerald-500/15 text-accent' : 'border-line text-ink-dim'" class="flex-1 border rounded py-1.5 text-sm font-mono">ENL</button>
                        <button type="button" @click="form.faction = 'RES'" :class="form.faction === 'RES' ? 'border-res bg-res/15 text-res' : 'border-line text-ink-dim'" class="flex-1 border rounded py-1.5 text-sm font-mono">RES</button>
                    </div>
                </div>
            </div>

            <div class="px-1.5 py-2.5 border-y border-line hud-label text-xs font-mono text-ink-dim uppercase tracking-wide">Contact <span class="normal-case text-ink-faint">— all optional, visible to your op</span></div>
            <div class="px-1.5 py-3 grid grid-cols-2 gap-2">
                <input v-model="form.phone" placeholder="phone" class="bg-inset border border-line rounded px-1.5 py-1.5 text-sm" />
                <input v-model="form.telegram" placeholder="telegram @handle" class="bg-inset border border-line rounded px-1.5 py-1.5 text-sm" />
                <input v-model="form.preferred_contact" placeholder="preferred contact" class="bg-inset border border-line rounded px-1.5 py-1.5 text-sm" />
                <input v-model="form.emergency_contact" placeholder="emergency contact" class="bg-inset border border-line rounded px-1.5 py-1.5 text-sm" />
            </div>

            <div class="px-1.5 py-3 border-t border-line space-y-3">
                <button type="submit" :disabled="form.processing" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-1.5 py-1.5 disabled:opacity-50">save profile</button>
                <span v-if="form.recentlySuccessful" class="inline-flex items-center gap-1 text-accent text-xs ml-2">saved <Check :size="13" /></span>
            </div>
        </form>

        <!-- BYOK AI concierge — your own provider key, kept in this browser (optionally synced) -->
        <section class="border border-line rounded-lg bg-surface overflow-hidden mb-6 max-w-lg">
            <div class="px-1.5 py-2.5 border-b border-line hud-label text-xs font-mono text-ink-dim uppercase tracking-wide flex items-center justify-between">
                <span>AI Concierge</span>
                <span class="font-mono text-[10px] normal-case" :class="aiReady ? 'text-accent' : 'text-ink-faint'">{{ aiReady ? 'active' : 'off' }}</span>
            </div>
            <div class="px-1.5 py-3 space-y-3">
                <p class="text-[11px] font-mono text-ink-faint leading-snug">
                    Bring your own API key to unlock the in-op concierge. Your key stays in this browser and is sent straight to your provider per request — toady never stores it unless you turn on sync below.
                </p>

                <div>
                    <label class="block text-xs font-mono text-ink-dim mb-1">PROVIDER</label>
                    <div class="flex gap-2">
                        <button type="button" @click="aiSwitchProvider('anthropic')" :class="aiProvider === 'anthropic' ? 'border-accent bg-emerald-500/15 text-accent' : 'border-line text-ink-dim'" class="flex-1 border rounded py-1.5 text-sm font-mono">Claude</button>
                        <button type="button" @click="aiSwitchProvider('openai')" :class="aiProvider === 'openai' ? 'border-accent bg-emerald-500/15 text-accent' : 'border-line text-ink-dim'" class="flex-1 border rounded py-1.5 text-sm font-mono">GPT</button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-mono text-ink-dim mb-1">API KEY</label>
                    <!-- type=text (not password) so the browser never offers to save an API key as a login; masked via
                         -webkit-text-security (Chromium/WebKit + Firefox 118+), with password-manager opt-outs. -->
                    <input type="text" v-model="aiKey" @change="aiLoadModels" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                        data-1p-ignore data-lpignore="true" placeholder="paste your API key…" style="-webkit-text-security: disc;"
                        class="w-full bg-inset border border-line rounded px-1.5 py-1.5 text-sm font-mono focus:border-accent focus:outline-none" />
                    <p class="text-[10px] font-mono text-ink-faint mt-1">{{ aiProvider === 'anthropic' ? 'console.anthropic.com → API keys' : 'platform.openai.com → API keys' }}</p>
                </div>

                <div>
                    <label class="block text-xs font-mono text-ink-dim mb-1">MODEL</label>
                    <select v-model="aiModel" :disabled="!aiModels.length" class="w-full bg-inset border border-line rounded px-1.5 py-1.5 text-sm font-mono focus:border-accent focus:outline-none disabled:opacity-40">
                        <option value="" disabled>{{ aiLoadingModels ? 'loading…' : (aiModels.length ? 'choose a model' : 'enter a key first') }}</option>
                        <option v-for="m in aiModels" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <p v-if="aiModelsError" class="text-[10px] font-mono text-rose-400 mt-1">{{ aiModelsError }}</p>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <span class="text-xs font-mono text-ink-dim">Sync to my other devices</span>
                        <p class="text-[10px] font-mono text-ink-faint mt-0.5">keeps an encrypted copy on your account so the key follows you. Off = this browser only.</p>
                    </div>
                    <button type="button" role="switch" :aria-checked="aiSync" @click="aiSync = !aiSync"
                        :class="aiSync ? 'bg-emerald-500/30 border-accent' : 'bg-inset border-line'"
                        class="relative shrink-0 w-11 h-6 rounded-full border transition-colors">
                        <span :class="aiSync ? 'translate-x-5 bg-accent' : 'translate-x-0 bg-ink-faint'" class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full transition-transform"></span>
                    </button>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button type="button" @click="aiSave" :disabled="!aiKey.trim()" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-50">save key</button>
                    <button v-if="aiKey || aiSync" type="button" @click="aiClear" class="text-ink-faint hover:text-rose-400 font-mono text-xs px-1.5">clear key</button>
                    <span v-if="aiFlash" class="inline-flex items-center gap-1 text-accent text-xs">saved <Check :size="13" /></span>
                </div>
            </div>
        </section>

        <section class="border border-line rounded-lg bg-surface overflow-hidden mb-6 max-w-lg">
            <div class="px-1.5 py-2.5 border-b border-line hud-label text-xs font-mono text-ink-dim uppercase tracking-wide">Prompts</div>
            <div class="px-1.5 py-3 space-y-3">
                <p class="text-[11px] font-mono text-ink-faint leading-snug">
                    Some confirmations let you tick “don’t ask again” — removing a location or directive, flagging a portal name, adding a portal from the map. Restore them all so toady asks before those actions again. This browser only.
                </p>
                <div class="flex items-center gap-3">
                    <button type="button" @click="resetPrompts" class="border border-line rounded px-3 py-1.5 font-mono text-sm text-ink-dim hover:text-accent hover:border-accent">Restore all prompts</button>
                    <span v-if="promptsFlash" class="inline-flex items-center gap-1 text-accent text-xs font-mono">restored <Check :size="13" /></span>
                </div>
            </div>
        </section>

        <section v-if="!profile.is_owner" class="border border-rose-500/30 rounded-lg bg-surface overflow-hidden max-w-lg">
            <div class="px-1.5 py-2.5 border-b border-rose-500/30 text-xs font-mono text-rose-300 uppercase tracking-wide">Danger zone</div>
            <div class="px-1.5 py-4">
                <p class="text-sm text-ink-dim mb-3">Delete your account and all data tied to you. Can't be undone.</p>
                <button v-if="!confirming" @click="confirming = true" class="border border-rose-500/40 text-rose-300 hover:bg-rose-500/10 font-mono text-sm rounded px-1.5 py-1.5">Delete account</button>
                <div v-else class="space-y-2">
                    <label class="block text-xs font-mono text-ink-faint">Type <span class="text-rose-300">DELETE</span></label>
                    <input v-model="typed" autofocus class="w-full max-w-xs bg-inset border border-line rounded px-1.5 py-1.5 text-sm" />
                    <div class="flex gap-2">
                        <button @click="del" :disabled="typed.trim().toUpperCase() !== 'DELETE'" class="bg-rose-500/80 hover:bg-rose-500 text-white font-mono text-sm font-semibold rounded px-1.5 py-1.5 disabled:opacity-40">Permanently delete</button>
                        <button @click="confirming = false; typed = ''" class="text-xs text-ink-faint px-2">cancel</button>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
