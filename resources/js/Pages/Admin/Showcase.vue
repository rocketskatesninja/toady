<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import { factionText } from '@/faction';
import { Images, Trash2, Pencil, X } from 'lucide-vue-next';

const props = defineProps({
    enabled: { type: Boolean, default: true },
    entries: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

// public page on/off — independent of individual entries' published flag
const enabledForm = useForm({ enabled: props.enabled });
function toggleEnabled() {
    enabledForm.enabled = ! enabledForm.enabled;
    enabledForm.put('/admin/showcase/enabled', { preserveScroll: true });
}

const editingId = ref(null);          // null = creating a new entry
const fileInput = ref(null);
const userFilter = ref('');
const existing = ref([]);             // [{ index, url }] of the entry being edited (only the ones still kept)
const newPreviews = ref([]);          // object-URL previews of newly-picked files
// keep = which existing image indices to retain; images = new files to add
const form = useForm({ title: '', story: '', credit: '', tagged_ids: [], published: true, keep: [], images: [] });

const filteredUsers = computed(() => {
    const q = userFilter.value.trim().toLowerCase();
    return q ? props.users.filter((u) => (u.callsign || '').toLowerCase().includes(q)) : props.users;
});
const totalPhotos = computed(() => form.keep.length + form.images.length);
const slotsFree = computed(() => Math.max(0, 5 - totalPhotos.value));

function clearPreviews() {
    newPreviews.value.forEach(URL.revokeObjectURL);
    newPreviews.value = [];
}
function syncPreviews() {
    clearPreviews();
    newPreviews.value = form.images.map((f) => URL.createObjectURL(f));
}

function startNew() {
    editingId.value = null;
    form.reset();
    form.keep = [];
    form.images = [];
    existing.value = [];
    clearPreviews();
    form.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
}
function startEdit(e) {
    editingId.value = e.id;
    form.title = e.title;
    form.story = e.story || '';
    form.credit = e.credit || '';
    form.tagged_ids = [...(e.tagged_ids || [])];
    form.published = e.published;
    form.images = [];
    form.keep = e.images.map((_, i) => i);                       // keep all existing to start
    existing.value = e.images.map((url, i) => ({ index: i, url }));
    clearPreviews();
    form.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function removeExisting(index) {
    form.keep = form.keep.filter((i) => i !== index);
    existing.value = existing.value.filter((x) => x.index !== index);
}
function onFiles(ev) {
    const picked = Array.from(ev.target.files || []);
    form.images = [...form.images, ...picked].slice(0, 5 - form.keep.length); // never exceed 5 total
    if (fileInput.value) fileInput.value.value = '';                          // allow re-picking the same file
    syncPreviews();
}
function removeNew(i) {
    form.images = form.images.filter((_, idx) => idx !== i);
    syncPreviews();
}
function toggleTag(id) {
    form.tagged_ids = form.tagged_ids.includes(id) ? form.tagged_ids.filter((x) => x !== id) : [...form.tagged_ids, id];
}
function submit() {
    const url = editingId.value ? `/admin/showcase/${editingId.value}` : '/admin/showcase';
    form.post(url, { forceFormData: true, preserveScroll: true, onSuccess: () => startNew() });
}
function remove(e) {
    if (confirm(`Remove “${e.title}” from the showcase?`)) router.delete(`/admin/showcase/${e.id}`, { preserveScroll: true });
}

onBeforeUnmount(clearPreviews);

const field = 'w-full bg-inset border border-line rounded px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none';
const thumb = 'w-16 h-16 rounded border object-cover';
</script>

<template>
    <Head title="Showcase — admin" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><Images :size="18" /> showcase</span></template>

        <div class="max-w-3xl mx-auto">
            <AdminNav current="showcase" class="mb-4">
                <a href="/showcase" target="_blank" rel="noopener" class="hover:text-accent">view public →</a>
            </AdminNav>

            <!-- public page on/off -->
            <div class="flex items-center justify-between border border-line rounded-lg bg-surface p-3 mb-6">
                <div>
                    <div class="text-sm text-ink">Public showcase page</div>
                    <p class="text-[11px] text-ink-faint mt-0.5">
                        Off takes <span class="font-mono">/showcase</span> down site-wide (404) and hides its nav links — entries below stay saved.
                    </p>
                </div>
                <button type="button" @click="toggleEnabled" :disabled="enabledForm.processing"
                    role="switch" :aria-checked="enabledForm.enabled"
                    :class="enabledForm.enabled ? 'bg-accent' : 'bg-inset border border-line'"
                    class="relative shrink-0 w-11 h-6 rounded-full transition-colors disabled:opacity-40">
                    <span :class="enabledForm.enabled ? 'translate-x-5' : 'translate-x-0'"
                        class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-bg shadow transition-transform"></span>
                </button>
            </div>

            <!-- editor -->
            <form @submit.prevent="submit" class="border border-line rounded-lg bg-surface p-4 mb-6 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-mono text-sm text-ink uppercase tracking-wide">{{ editingId ? 'Edit entry' : 'New entry' }}</h2>
                    <button v-if="editingId" type="button" @click="startNew" class="text-[11px] font-mono text-ink-faint hover:text-accent">+ new instead</button>
                </div>

                <div>
                    <input v-model="form.title" placeholder="title — e.g. Brunswick fan · 14 layers" :class="field" />
                    <p v-if="form.errors.title" class="text-[11px] text-rose-400 mt-0.5">{{ form.errors.title }}</p>
                </div>
                <textarea v-model="form.story" spellcheck="true" rows="4" placeholder="the story — how the op went…" :class="field"></textarea>
                <input v-model="form.credit" placeholder="credit — who sent it in (free text)" :class="field" />

                <!-- photos -->
                <div>
                    <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1.5">
                        Photos — the op plus your crew, up to 5 · <span :class="totalPhotos > 5 ? 'text-rose-400' : 'text-ink-dim'">{{ totalPhotos }}/5</span>
                    </div>
                    <div v-if="existing.length || newPreviews.length" class="flex flex-wrap gap-2 mb-2">
                        <div v-for="x in existing" :key="'e' + x.index" class="relative">
                            <img :src="x.url" :class="[thumb, 'border-line']" alt="" />
                            <button type="button" @click="removeExisting(x.index)" title="delete this photo"
                                class="absolute -top-1.5 -right-1.5 bg-bg border border-line rounded-full p-0.5 text-ink-faint hover:text-rose-400"><X :size="12" /></button>
                        </div>
                        <div v-for="(src, i) in newPreviews" :key="'n' + i" class="relative">
                            <img :src="src" :class="[thumb, 'border-accent/50']" alt="" />
                            <span class="absolute bottom-0 inset-x-0 bg-accent/80 text-accent-ink text-[8px] font-mono text-center leading-tight">new</span>
                            <button type="button" @click="removeNew(i)" title="remove"
                                class="absolute -top-1.5 -right-1.5 bg-bg border border-line rounded-full p-0.5 text-ink-faint hover:text-rose-400"><X :size="12" /></button>
                        </div>
                    </div>
                    <input ref="fileInput" type="file" accept="image/*" multiple @change="onFiles" :disabled="slotsFree === 0"
                        class="block w-full text-xs text-ink-dim file:mr-2 file:rounded file:border file:border-line file:bg-inset file:px-2 file:py-1 file:font-mono file:text-ink-dim disabled:opacity-40" />
                    <p class="text-[11px] text-ink-faint mt-1">{{ slotsFree ? `add up to ${slotsFree} more` : 'all 5 slots used — delete one to add another' }}</p>
                    <p v-if="form.errors['images.0'] || form.errors.images" class="text-[11px] text-rose-400 mt-0.5">{{ form.errors['images.0'] || form.errors.images }}</p>
                </div>

                <!-- tag agents -->
                <div>
                    <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Tag agents ({{ form.tagged_ids.length }})</div>
                    <input v-model="userFilter" placeholder="filter callsigns…" :class="[field, 'mb-1.5']" />
                    <div class="max-h-40 overflow-y-auto border border-line rounded divide-y divide-line/60">
                        <label v-for="u in filteredUsers" :key="u.id" class="flex items-center gap-2 px-2 py-1 text-sm cursor-pointer hover:bg-emerald-500/5">
                            <input type="checkbox" :checked="form.tagged_ids.includes(u.id)" @change="toggleTag(u.id)" class="accent-accent" />
                            <span class="text-ink">{{ u.callsign }}</span>
                            <span v-if="u.faction" :class="factionText(u.faction)" class="text-[10px] font-mono">[{{ u.faction }}]</span>
                        </label>
                        <p v-if="!filteredUsers.length" class="px-2 py-2 text-xs text-ink-faint">no matches</p>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-ink-dim">
                    <input type="checkbox" v-model="form.published" class="accent-accent" /> published (live on the public page)
                </label>

                <div class="flex items-center gap-2 pt-1">
                    <button type="submit" :disabled="form.processing" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-40">{{ editingId ? 'Save changes' : 'Add to showcase' }}</button>
                    <button v-if="editingId" type="button" @click="startNew" class="text-sm font-mono text-ink-faint hover:text-ink">cancel</button>
                </div>
            </form>

            <!-- list -->
            <div v-for="e in entries" :key="e.id" class="border border-line rounded-lg bg-surface p-3 mb-3" :class="e.published ? '' : 'opacity-60'">
                <div class="flex items-start gap-3">
                    <div v-if="e.images.length" class="flex gap-1 shrink-0">
                        <img v-for="(src, i) in e.images" :key="i" :src="src" class="w-14 h-14 rounded border border-line object-cover" alt="" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-ink truncate">{{ e.title }}</span>
                            <span v-if="!e.published" class="text-[10px] font-mono uppercase text-ink-faint border border-line rounded px-1">draft</span>
                        </div>
                        <p v-if="e.story" class="text-[11px] text-ink-faint truncate mt-0.5">{{ e.story }}</p>
                        <div class="mt-0.5 text-[10px] font-mono text-ink-faint">{{ e.credit ? 'by ' + e.credit + ' · ' : '' }}{{ e.images.length }} photos · {{ e.tagged_ids.length }} tagged · {{ e.date }}</div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button @click="startEdit(e)" class="text-ink-dim hover:text-accent" title="edit"><Pencil :size="15" /></button>
                        <button @click="remove(e)" class="text-ink-faint hover:text-rose-400" title="remove"><Trash2 :size="15" /></button>
                    </div>
                </div>
            </div>
            <p v-if="!entries.length" class="text-center text-ink-faint text-sm py-12">No showcase entries yet — add the first above.</p>
        </div>
    </AppLayout>
</template>
