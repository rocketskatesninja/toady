<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { X, ImagePlus, Trash2, CircleCheck, Send } from 'lucide-vue-next';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const form = useForm({ message: '', reply_email: '', url: '', screenshots: [] });
const previews = ref([]);
const sent = ref(false);
const fileInput = ref(null);

function addFiles(e) {
    const combined = [...form.screenshots, ...Array.from(e.target.files || [])].slice(0, 4);
    form.screenshots = combined;
    previews.value = combined.map((f) => ({ url: URL.createObjectURL(f) }));
    if (fileInput.value) fileInput.value.value = '';
}
function removeFile(i) {
    form.screenshots = form.screenshots.filter((_, idx) => idx !== i);
    previews.value = previews.value.filter((_, idx) => idx !== i);
}
function submit() {
    form.url = window.location.href;
    form.post('/reports', {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { sent.value = true; setTimeout(close, 1500); },
    });
}
function close() {
    sent.value = false;
    form.reset();
    form.clearErrors();
    previews.value = [];
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[90] flex items-end sm:items-center justify-center p-2 sm:p-4">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="close"></div>
            <div class="relative w-full max-w-md bg-surface border border-line rounded-xl shadow-2xl">
                <div v-if="sent" class="p-8 text-center">
                    <CircleCheck :size="40" class="mx-auto text-accent" :stroke-width="1.5" />
                    <p class="mt-3 font-mono text-accent">Report sent.</p>
                    <p class="mt-1 text-sm text-ink-dim">Thanks for the heads-up.</p>
                </div>
                <form v-else @submit.prevent="submit">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-line bg-emerald-500/5">
                        <h2 class="font-mono text-ink tracking-wide">Report a problem</h2>
                        <button type="button" @click="close" title="close" class="text-ink-faint hover:text-accent"><X :size="18" /></button>
                    </div>
                    <div class="p-4 space-y-3">
                        <p class="text-xs text-ink-dim leading-relaxed">Found a bug or something off? Tell us what happened — a screenshot helps a ton.</p>
                        <textarea v-model="form.message" spellcheck="true" rows="4" autofocus required maxlength="5000"
                            placeholder="What went wrong? What were you trying to do?"
                            class="w-full bg-inset border border-line rounded px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none resize-y"></textarea>
                        <p v-if="form.errors.message" class="text-rose-400 text-xs">{{ form.errors.message }}</p>

                        <div>
                            <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="addFiles" />
                            <button type="button" @click="fileInput?.click()" :disabled="form.screenshots.length >= 4"
                                class="inline-flex items-center gap-1.5 text-xs font-mono border border-line rounded px-2 py-1.5 text-ink-dim hover:text-accent hover:border-accent disabled:opacity-40">
                                <ImagePlus :size="14" /> Add screenshot{{ form.screenshots.length ? ` (${form.screenshots.length}/4)` : '' }}
                            </button>
                            <div v-if="previews.length" class="mt-2 flex flex-wrap gap-2">
                                <div v-for="(p, i) in previews" :key="i" class="relative w-16 h-16 rounded border border-line overflow-hidden">
                                    <img :src="p.url" class="w-full h-full object-cover" alt="" />
                                    <button type="button" @click="removeFile(i)" title="remove screenshot" class="absolute top-0.5 right-0.5 bg-black/60 rounded p-0.5 text-ink hover:text-rose-400"><Trash2 :size="12" /></button>
                                </div>
                            </div>
                            <p v-if="form.errors['screenshots.0'] || form.errors.screenshots" class="text-rose-400 text-xs mt-1">A screenshot must be an image under 5 MB.</p>
                        </div>

                        <div>
                            <label class="block text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Email (optional)</label>
                            <input v-model="form.reply_email" type="email" placeholder="you@example.com"
                                class="w-full bg-inset border border-line rounded px-2 py-1.5 text-sm font-mono text-ink focus:border-accent focus:outline-none" />
                            <p class="text-[11px] text-ink-faint mt-1">Leave your email if you'd like a reply — totally optional.</p>
                            <p v-if="form.errors.reply_email" class="text-rose-400 text-xs">{{ form.errors.reply_email }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 px-4 py-3 border-t border-line">
                        <button type="submit" :disabled="form.processing || !form.message.trim()"
                            class="inline-flex items-center gap-1.5 bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-50">
                            <Send :size="14" /> Send report
                        </button>
                        <button type="button" @click="close" class="text-xs text-ink-faint hover:text-ink px-2">cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
