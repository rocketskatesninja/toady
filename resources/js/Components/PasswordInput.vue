<script setup>
// A password input with a show/hide eye toggle. v-model + passthrough for autocomplete/autofocus.
import { ref } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

defineProps({
    modelValue: { type: String, default: '' },
    autocomplete: { type: String, default: undefined },
    autofocus: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
const show = ref(false);
</script>

<template>
    <div class="relative">
        <input
            :type="show ? 'text' : 'password'"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            :autocomplete="autocomplete"
            :autofocus="autofocus"
            class="w-full bg-inset border border-line rounded px-1.5 py-2 pr-9 text-sm focus:border-accent focus:outline-none"
        />
        <button type="button" tabindex="-1" @click="show = !show"
            :aria-label="show ? 'Hide password' : 'Show password'"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-ink-faint hover:text-accent">
            <component :is="show ? EyeOff : Eye" :size="16" />
        </button>
    </div>
</template>
