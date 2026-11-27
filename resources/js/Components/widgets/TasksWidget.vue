<script setup>
import { inject, ref, computed } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import { ui, up } from '@/ui';

// The op's plain checklist — not the plan: no portals, actions or order, just lines. Operators add, assign and
// delete tasks; an unassigned task ("anyone") can be checked off by anyone on the op, an assigned one only by
// its agent or an operator.
const c = inject('opctx');

const text = ref('');
const assignee = ref(''); // '' = anyone
function add() {
    const t = text.value.trim();
    if (!t) return;
    c.addOpTask(t, assignee.value);
    text.value = '';
    assignee.value = ''; // back to the default: anyone
}

// open tasks first, then done ones — each in the order they were added
const tasks = computed(() => [...c.data.tasks].sort((a, b) => a.done - b.done));
const doneCount = computed(() => c.data.tasks.filter((t) => t.done).length);
// why this viewer can't check a task off ('' = they can)
const blocked = (t) => (t.assignee_id && t.assignee_id !== c.me && !c.manage ? `Only ${t.assignee} can check this off` : '');
</script>

<template>
    <div class="h-full flex flex-col">
        <!-- operators: one add row -->
        <form v-if="c.manage" @submit.prevent="add" class="shrink-0 flex gap-1.5 p-1.5 border-b border-line">
            <input v-model="text" maxlength="200" placeholder="add a task…" :class="ui.input" />
            <select v-model="assignee" :class="ui.select" class="w-28! shrink-0" title="assigned to">
                <option value="">ANYONE</option>
                <option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ up(p.callsign) }}</option>
            </select>
            <button type="submit" :disabled="!text.trim()" :class="ui.primary" class="w-9 shrink-0 disabled:opacity-40" title="add task"><Plus :size="15" /></button>
        </form>

        <ul v-if="tasks.length" class="flex-1 min-h-0 overflow-y-auto divide-y divide-line">
            <li v-for="t in tasks" :key="t.id" class="flex items-center gap-2 px-2 py-1.5">
                <input type="checkbox" :checked="t.done" @change="c.toggleOpTask(t)" :disabled="!!blocked(t)"
                    :title="blocked(t) || (t.done ? `done by ${t.done_by || 'someone'}` : 'check off')"
                    class="size-5 shrink-0 accent-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed" />
                <span class="flex-1 min-w-0 text-sm break-words" :class="t.done ? 'line-through text-ink-faint' : 'text-ink'">{{ t.text }}</span>
                <template v-if="c.manage">
                    <select :value="t.assignee_id || ''" @change="(e) => c.assignOpTask(t, e)" :class="ui.select" class="w-28! shrink-0" title="assigned to">
                        <option value="">ANYONE</option>
                        <option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ up(p.callsign) }}</option>
                    </select>
                    <button type="button" @click="c.removeOpTask(t)" :class="ui.iconDanger" title="delete task"><X :size="14" /></button>
                </template>
                <span v-else class="shrink-0 text-[11px] font-mono" :class="t.assignee_id === c.me ? 'text-accent' : 'text-ink-faint'">
                    {{ t.assignee ? '@' + t.assignee : 'anyone' }}
                </span>
            </li>
        </ul>
        <div v-else class="flex-1 flex flex-col items-center justify-center text-center text-xs text-ink-faint px-3 py-4">
            <p>No tasks yet.</p>
            <p v-if="!c.manage" class="mt-0.5">Your operator adds them here.</p>
        </div>

        <p v-if="tasks.length" class="shrink-0 px-2 py-1 border-t border-line text-[10px] font-mono text-ink-faint">{{ doneCount }}/{{ tasks.length }} done</p>
    </div>
</template>
