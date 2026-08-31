<script setup>
import { ref, watch, onMounted } from 'vue';
import { Bold, Italic, Underline, Heading, List, Link2 } from 'lucide-vue-next';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);

const el = ref(null);
const sync = () => emit('update:modelValue', el.value?.innerHTML || '');
function cmd(c, v = null) { document.execCommand(c, false, v); el.value?.focus(); sync(); }
function addLink() {
    const url = prompt('Link URL:');
    if (url) cmd('createLink', url);
}

onMounted(() => { if (el.value) el.value.innerHTML = props.modelValue || ''; });
// reflect external changes (e.g. a reset) without clobbering the caret while typing
watch(() => props.modelValue, (v) => { if (el.value && v !== el.value.innerHTML) el.value.innerHTML = v || ''; });

const btn = 'p-1 rounded hover:bg-emerald-500/10 hover:text-accent';
</script>

<template>
    <div class="border border-line rounded bg-inset overflow-hidden focus-within:border-accent">
        <div class="flex items-center gap-0.5 px-1.5 py-1 border-b border-line/60 text-ink-dim">
            <button type="button" @click="cmd('bold')" :class="btn" title="Bold"><Bold :size="14" /></button>
            <button type="button" @click="cmd('italic')" :class="btn" title="Italic"><Italic :size="14" /></button>
            <button type="button" @click="cmd('underline')" :class="btn" title="Underline"><Underline :size="14" /></button>
            <span class="w-px h-4 bg-line mx-1"></span>
            <button type="button" @click="cmd('formatBlock', 'h2')" :class="btn" title="Heading"><Heading :size="14" /></button>
            <button type="button" @click="cmd('insertUnorderedList')" :class="btn" title="Bullet list"><List :size="14" /></button>
            <button type="button" @click="addLink" :class="btn" title="Insert link"><Link2 :size="14" /></button>
        </div>
        <div ref="el" contenteditable="true" @input="sync" @blur="sync"
            class="rt-body px-2.5 py-2 min-h-[160px] max-h-[360px] overflow-auto text-sm text-ink leading-relaxed focus:outline-none op-scroll"></div>
    </div>
</template>

<style scoped>
.rt-body :deep(h2) { font-size: 1.1rem; font-weight: 600; margin: 0.5em 0 0.25em; color: var(--color-ink); }
.rt-body :deep(ul) { list-style: disc; padding-left: 1.25rem; margin: 0.4em 0; }
.rt-body :deep(a) { color: var(--color-accent); text-decoration: underline; }
.rt-body :deep(b), .rt-body :deep(strong) { color: var(--color-ink); }
</style>
