<script setup>
import { Link } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';

defineProps({
    subtitle: String,
    // When set, the ▲ toady brand links here; otherwise it's static text.
    brandHref: String,
});
</script>

<template>
    <div class="min-h-screen text-ink font-sans flex items-center justify-center px-1">
        <div class="w-full max-w-sm">
            <component :is="brandHref ? Link : 'div'" :href="brandHref"
                class="block text-center mb-6">
                <span class="font-mono text-accent glow tracking-[0.3em] text-2xl"><BrandMark /> toady</span>
                <p v-if="subtitle" class="text-accent/50 text-[10px] font-mono mt-1.5 tracking-[0.3em]">{{ subtitle }}</p>
            </component>

            <slot />
        </div>
    </div>
</template>
