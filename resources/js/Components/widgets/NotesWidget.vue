<script setup>
import { inject, ref, watch } from 'vue';

// Two tabs: a private per-agent notepad, and op-wide notes the operator shares with everyone.
// Private = op_participants.notes (only you). Op = ops.shared_notes (operator writes, all read; polled live).
const c = inject('opctx');
const tab = ref('mine');

// private notes
const mine = ref(c.data.myNotes || '');
let mt;
function saveMine() { clearTimeout(mt); c.saveNotes(mine.value, 'mine'); }
function onMineInput() { clearTimeout(mt); mt = setTimeout(saveMine, 600); }

// shared op notes (operator-editable)
const shared = ref(c.data.op.shared_notes || '');
const sharedFocused = ref(false);
let st;
function saveShared() { clearTimeout(st); c.saveNotes(shared.value, 'op'); }
function onSharedInput() { clearTimeout(st); st = setTimeout(saveShared, 600); }
function onSharedBlur() { sharedFocused.value = false; saveShared(); }
// adopt live poll updates into the editor only when the operator isn't mid-edit
watch(() => c.data.op.shared_notes, (v) => { if (!sharedFocused.value) shared.value = v || ''; });

const ta = 'flex-1 w-full min-h-[6rem] bg-inset border border-line rounded px-2 py-1.5 text-sm text-ink leading-relaxed focus:border-accent focus:outline-none resize-none';
const tabBtn = (on) => `px-2 py-1 border-b-2 ${on ? 'text-accent border-accent' : 'text-ink-faint border-transparent hover:text-ink-dim'}`;
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="flex items-center gap-1 px-1.5 pt-1.5 text-[10px] font-mono uppercase tracking-wide">
            <button @click="tab = 'mine'" :class="tabBtn(tab === 'mine')">My notes</button>
            <button @click="tab = 'op'" :class="tabBtn(tab === 'op')">Op notes</button>
        </div>

        <!-- private -->
        <div v-if="tab === 'mine'" class="flex-1 flex flex-col px-1.5 py-2">
            <textarea v-model="mine" @input="onMineInput" @blur="saveMine"
                placeholder="your private notes for this op — only you can see these…" :class="ta"></textarea>
            <p class="mt-1 shrink-0 text-[10px] font-mono text-ink-faint">private · saved automatically · cleared when the op closes</p>
        </div>

        <!-- shared op notes -->
        <div v-else class="flex-1 flex flex-col px-1.5 py-2">
            <textarea v-if="c.manage" v-model="shared" @input="onSharedInput" @focus="sharedFocused = true" @blur="onSharedBlur"
                placeholder="notes for the whole op — everyone on the op sees these…" :class="ta"></textarea>
            <template v-else>
                <p v-if="c.data.op.shared_notes" class="flex-1 overflow-y-auto text-sm text-ink leading-relaxed whitespace-pre-line">{{ c.data.op.shared_notes }}</p>
                <p v-else class="flex-1 flex items-center justify-center text-ink-faint text-xs">No op notes yet.</p>
            </template>
            <p class="mt-1 shrink-0 text-[10px] font-mono text-ink-faint">{{ c.manage ? 'shared with the whole op · saved automatically' : 'shared by your operator · read-only' }}</p>
        </div>
    </div>
</template>
