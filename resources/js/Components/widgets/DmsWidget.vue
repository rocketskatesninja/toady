<script setup>
import { inject } from 'vue';
import DmPanel from '@/Components/DmPanel.vue';

const c = inject('opctx');
</script>

<template>
    <DmPanel :op-id="c.data.op.id" :me="c.me" :participants="c.data.participants" :can-moderate="c.manage" />
</template>
