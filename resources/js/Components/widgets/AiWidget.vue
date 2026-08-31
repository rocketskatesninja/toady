<script setup>
import { inject, ref, computed, nextTick, watch } from 'vue';
import { Bot, Send, Settings2, Loader2, SquarePen, Share2, Target, ListChecks, KeyRound, MapPin, Waves } from 'lucide-vue-next';

// BYOK AI concierge. The key lives ONLY in this browser (localStorage) — it's forwarded to the provider
// per-request by our same-origin proxy and never stored on our servers. Two providers: Anthropic, OpenAI.
const c = inject('opctx');
const LS = 'toady-ai';
let saved = {};
try { saved = JSON.parse(localStorage.getItem(LS) || '{}'); } catch (e) { /* */ }
const serverCfg = c.data.aiConfig || null; // synced config (encrypted server-side) when cross-device sync is on
const src = serverCfg || saved;

const provider = ref(src.provider || 'anthropic');
const apiKey = ref(src.key || '');
const model = ref(src.model || '');
const synced = ref(!!serverCfg);
let initialized = false;
const models = ref([]);
const loadingModels = ref(false);
const modelsError = ref('');
const settingsOpen = ref(!apiKey.value || !model.value);

function persist() {
    localStorage.setItem(LS, JSON.stringify({ provider: provider.value, key: apiKey.value, model: model.value }));
    if (synced.value && initialized && apiKey.value.trim()) saveServerCfg();
}
function saveServerCfg() {
    window.axios.put('/profile/ai-config', { provider: provider.value, key: apiKey.value.trim(), model: model.value }).catch(() => {});
}
function toggleSync(e) {
    synced.value = e.target.checked;
    if (! synced.value) window.axios.delete('/profile/ai-config').catch(() => {}); // stop syncing → wipe the stored copy
    else if (apiKey.value.trim()) saveServerCfg();
}

async function loadModels() {
    modelsError.value = '';
    persist();
    if (!apiKey.value.trim()) { models.value = []; return; }
    loadingModels.value = true;
    try {
        const { data } = await window.axios.post(`/ops/${c.data.op.id}/ai/models`, { provider: provider.value, key: apiKey.value.trim() });
        models.value = data.models || [];
        if (!model.value || !models.value.includes(model.value)) model.value = models.value[0] || '';
        persist();
    } catch (e) {
        modelsError.value = e.response?.data?.error || 'Could not load models — check the key.';
        models.value = [];
    } finally {
        loadingModels.value = false;
    }
}
watch(provider, () => { model.value = ''; models.value = []; loadModels(); });

// ---- chat ---- (persisted per-op so the conversation survives reloads/navigation/mode-switches until "new chat")
const CHAT_LS = `toady-ai-chat:${c.data.op.id}`;
let savedChat = [];
try { savedChat = JSON.parse(localStorage.getItem(CHAT_LS) || '[]'); } catch (e) { /* */ }
const messages = ref(Array.isArray(savedChat) ? savedChat : []);
const input = ref('');
const inputEl = ref(null);
const busy = ref(false);
const toolHint = ref('');
const scroller = ref(null);
const ready = computed(() => apiKey.value.trim() && model.value && !busy.value);

// persist the conversation (debounced — streaming mutates it token by token); newChat() clears it
let chatSaveT;
watch(messages, () => {
    clearTimeout(chatSaveT);
    chatSaveT = setTimeout(() => { try { localStorage.setItem(CHAT_LS, JSON.stringify(messages.value)); } catch (e) { /* */ } }, 500);
}, { deep: true });

const suggestions = [
    { icon: Target, label: 'Auto', t: 'How do I use auto to build the fan for this op?' },
    { icon: ListChecks, label: "What's left?", t: "What's left to finish here?" },
    { icon: KeyRound, label: 'Key status', t: 'Who still needs to farm keys?' },
    { icon: MapPin, label: 'Getting there', t: 'How do I reach these portals — parking & access?' },
    { icon: Waves, label: 'Tides', t: 'Any tide windows to plan around?' },
];
function suggest(t) { input.value = t; ready.value ? send() : (settingsOpen.value = true); }
// push an AI answer into notes (appends). scope 'op' = the op's shared notes (operators only); 'mine' = your private notes.
const sharedOpIdx = ref(-1);
const sharedMineIdx = ref(-1);
function newChat() { messages.value = []; input.value = ''; toolHint.value = ''; sharedOpIdx.value = -1; sharedMineIdx.value = -1; }

// Render a message as safe HTML: escape everything first (no HTML injection from the model's output), then
// turn markdown links [text](url) and bare http(s) URLs into real clickable links.
const esc = (s) => s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const anchor = (url, text) => `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-accent underline break-all">${text}</a>`;
function renderContent(content) {
    let html = esc(content || '');
    // links first (before the * _ ` transforms can touch a URL)
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, text, url) => anchor(url, text)); // [text](url)
    html = html.replace(/(?<!href=")(https?:\/\/[^\s<]+)/g, (m) => {                                    // bare URLs
        const trail = m.match(/[.,;:!?)\]}'"]+$/)?.[0] || ''; // don't swallow trailing punctuation
        const url = m.slice(0, m.length - trail.length);
        return anchor(url, url) + trail;
    });
    // basic markdown — order matters: code, then bold, bullets, italic
    html = html.replace(/`([^`\n]+)`/g, '<code class="bg-inset rounded px-1 font-mono text-[0.85em]">$1</code>'); // `code`
    html = html.replace(/\*\*([^*\n]+?)\*\*/g, '<strong class="text-ink font-semibold">$1</strong>');            // **bold**
    html = html.replace(/^[ \t]*[-*][ \t]+/gm, '• ');                                                        // "- x" / "* x" → • x
    html = html.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');                                                        // *italic*
    return html;
}
function shareNote(content, i, scope) {
    const isOp = scope === 'op';
    const cur = (isOp ? c.data.op.shared_notes : c.data.myNotes) || '';
    const next = (cur ? cur + '\n\n' : '') + '🤖 ' + content;
    c.saveNotes(next, scope);
    if (isOp) c.data.op.shared_notes = next; else c.data.myNotes = next; // optimistic so consecutive shares stack
    const r = isOp ? sharedOpIdx : sharedMineIdx;
    r.value = i;
    setTimeout(() => { if (r.value === i) r.value = -1; }, 2500);
}

function scrollDown() { nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; }); }
function xsrf() { const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/); return m ? decodeURIComponent(m[1]) : ''; }

async function send() {
    const text = input.value.trim();
    if (!text || !ready.value) return;
    settingsOpen.value = false;
    messages.value.push({ role: 'user', content: text });
    input.value = '';
    inputEl.value?.focus(); // keep the cursor in the box so they can keep typing
    const outgoing = messages.value.map((m) => ({ role: m.role, content: m.content })); // history incl. this turn
    messages.value.push({ role: 'assistant', content: '' });
    const reply = messages.value[messages.value.length - 1]; // the reactive element — mutate reply.content to stream
    busy.value = true;
    scrollDown();
    try {
        const res = await fetch(`/ops/${c.data.op.id}/ai`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'text/event-stream' },
            body: JSON.stringify({ provider: provider.value, key: apiKey.value.trim(), model: model.value, messages: outgoing }),
        });
        if (!res.ok || !res.body) { reply.content = `⚠️ Request failed (${res.status}).`; return; }
        const reader = res.body.getReader();
        const dec = new TextDecoder();
        let buf = '';
        for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            buf += dec.decode(value, { stream: true });
            let i;
            while ((i = buf.indexOf('\n\n')) !== -1) {
                const line = buf.slice(0, i).split('\n').find((l) => l.startsWith('data:'));
                buf = buf.slice(i + 2);
                if (!line) continue;
                const p = line.slice(5).trim();
                if (p === '[DONE]') continue;
                try { const o = JSON.parse(p); if (o.t) { reply.content += o.t; toolHint.value = ''; scrollDown(); } if (o.tool) { toolHint.value = o.tool; scrollDown(); } if (o.error) reply.content += `\n\n⚠️ ${o.error}`; } catch (e) { /* ignore partials */ }
            }
        }
    } catch (e) {
        reply.content += '\n\n⚠️ Connection failed.';
    } finally {
        busy.value = false;
        toolHint.value = '';
        scrollDown();
    }
}

if (apiKey.value.trim()) loadModels();
if (messages.value.length) scrollDown(); // restored conversation → jump to the latest
initialized = true; // from here on, edits propagate to the server when sync is on
</script>

<template>
    <div class="h-full flex flex-col">
        <!-- BYOK setup strip -->
        <div class="px-1.5 pt-2 shrink-0">
            <div class="flex items-center gap-2">
                <button @click="settingsOpen = !settingsOpen" class="flex items-center gap-1.5 text-[11px] font-mono uppercase tracking-wide text-ink-faint hover:text-accent shrink-0">
                    <Settings2 :size="12" /> AI setup
                </button>
                <span v-if="!settingsOpen && model" class="min-w-0 truncate text-[10px] font-mono text-accent">{{ provider }} · {{ model }}</span>
                <button v-if="messages.length" @click="newChat" title="Start a new chat" class="ml-auto flex items-center gap-1 text-[10px] font-mono uppercase tracking-wide text-ink-faint hover:text-accent shrink-0">
                    <SquarePen :size="12" /> new chat
                </button>
            </div>
            <div v-if="settingsOpen" class="mt-1 space-y-1 border border-line rounded p-1.5 bg-inset">
                <div class="flex items-center gap-1.5">
                    <div class="flex gap-0.5 shrink-0">
                        <button v-for="p in ['anthropic', 'openai']" :key="p" @click="provider = p" :class="provider === p ? 'border-accent text-accent' : 'border-line text-ink-faint'" class="text-[10px] font-mono uppercase border rounded px-1.5 py-1">{{ p === 'anthropic' ? 'Claude' : 'GPT' }}</button>
                    </div>
                    <input type="password" v-model="apiKey" @change="loadModels" placeholder="API key…" class="flex-[3] min-w-0 bg-surface border border-line rounded px-2 py-1 text-xs font-mono focus:border-accent focus:outline-none" />
                    <select v-model="model" @change="persist" :disabled="!models.length" class="flex-1 min-w-0 bg-surface border border-line rounded px-1 py-1 text-xs font-mono focus:border-accent focus:outline-none disabled:opacity-40">
                        <option value="" disabled>{{ loadingModels ? 'loading…' : (models.length ? 'model' : 'enter key first') }}</option>
                        <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <Loader2 v-if="loadingModels" :size="13" class="animate-spin text-accent shrink-0" />
                </div>
                <label class="flex items-center gap-1.5 text-[10px] text-ink-dim cursor-pointer">
                    <input type="checkbox" :checked="synced" @change="toggleSync" class="accent-emerald-500" />
                    Sync this key to my other devices
                </label>
                <p class="text-[10px] text-ink-faint leading-tight">{{ synced ? 'Stored encrypted on your account — syncs across your devices, used per request.' : 'Key stays in this browser only — never stored on our servers.' }}</p>
                <p v-if="modelsError" class="text-[10px] text-rose-400 leading-tight">{{ modelsError }}</p>
            </div>
        </div>

        <!-- conversation -->
        <div ref="scroller" class="flex-1 overflow-y-auto px-2 py-2 space-y-2 min-h-[6rem]">
            <div v-if="!messages.length" class="flex flex-col items-center justify-center h-full py-4">
                <div class="grid place-items-center w-11 h-11 rounded-full bg-emerald-500/10 border border-emerald-500/25 mb-2.5">
                    <Bot :size="20" class="text-accent" />
                </div>
                <p class="text-sm font-medium text-ink-dim">How can I help with this op?</p>
                <p class="text-[11px] text-ink-faint mt-0.5 mb-4">Ask anything, or tap a bubble</p>
                <div class="flex flex-wrap justify-center gap-2 px-1">
                    <button v-for="s in suggestions" :key="s.t" type="button" @click="suggest(s.t)"
                        class="group inline-flex items-center gap-1.5 rounded-full border border-line bg-inset px-3 py-1.5 text-xs text-ink-dim hover:border-accent hover:text-ink transition-colors">
                        <component :is="s.icon" :size="13" class="text-ink-faint group-hover:text-accent transition-colors" />
                        {{ s.label }}
                    </button>
                </div>
            </div>
            <div v-for="(m, i) in messages" :key="i" :class="m.role === 'user' ? 'text-right' : ''">
                <div :class="m.role === 'user' ? 'bg-emerald-500/15 text-ink' : 'bg-surface border border-line text-ink-dim'" class="inline-block max-w-[90%] text-left text-sm rounded px-2 py-1.5 whitespace-pre-line leading-relaxed" v-html="m.content ? renderContent(m.content) : '…'"></div>
                <div v-if="m.role === 'assistant' && m.content && !busy" class="mt-0.5 flex items-center gap-3">
                    <button v-if="c.manage" type="button" @click="shareNote(m.content, i, 'op')" class="inline-flex items-center gap-1 text-[10px] font-mono text-ink-faint hover:text-accent">
                        <Share2 :size="10" /> {{ sharedOpIdx === i ? 'Shared to op notes ✓' : 'Share to op notes' }}
                    </button>
                    <button type="button" @click="shareNote(m.content, i, 'mine')" class="inline-flex items-center gap-1 text-[10px] font-mono text-ink-faint hover:text-accent">
                        <Share2 :size="10" /> {{ sharedMineIdx === i ? 'Shared to my notes ✓' : 'Share to my notes' }}
                    </button>
                </div>
            </div>
            <div v-if="busy && toolHint" class="flex items-center gap-1.5 text-[11px] font-mono text-accent"><Loader2 :size="12" class="animate-spin" /> consulting {{ toolHint }}…</div>
        </div>

        <!-- prompt -->
        <form @submit.prevent="send" class="flex gap-1.5 p-1.5 border-t border-line shrink-0">
            <input ref="inputEl" v-model="input" spellcheck="true" :placeholder="ready ? 'Ask the concierge…' : 'add your API key + model above'" class="flex-1 bg-inset border border-line rounded px-2 py-1.5 text-sm focus:border-accent focus:outline-none" />
            <button type="submit" :disabled="!ready || !input.trim()" class="bg-accent hover:bg-emerald-400 text-accent-ink rounded px-2.5 disabled:opacity-40"><component :is="busy ? Loader2 : Send" :size="15" :class="busy ? 'animate-spin' : ''" /></button>
        </form>
    </div>
</template>
