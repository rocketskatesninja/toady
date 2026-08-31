<script setup>
import MapWidget from '@/Components/widgets/MapWidget.vue';
import PlanWidget from '@/Components/widgets/PlanWidget.vue';
import RosterWidget from '@/Components/widgets/RosterWidget.vue';
import DmsWidget from '@/Components/widgets/DmsWidget.vue';
import WeatherWidget from '@/Components/widgets/WeatherWidget.vue';
import NotificationsWidget from '@/Components/widgets/NotificationsWidget.vue';
import ProgressWidget from '@/Components/widgets/ProgressWidget.vue';
import AdvisoriesWidget from '@/Components/widgets/AdvisoriesWidget.vue';
import ActivityWidget from '@/Components/widgets/ActivityWidget.vue';
import NotesWidget from '@/Components/widgets/NotesWidget.vue';
import AiWidget from '@/Components/widgets/AiWidget.vue';
import CycleWidget from '@/Components/widgets/CycleWidget.vue';

defineProps({ widget: { type: String, required: true } });

const registry = {
    map: MapWidget,
    plan: PlanWidget,
    roster: RosterWidget,
    dms: DmsWidget,
    weather: WeatherWidget,
    notifications: NotificationsWidget,
    progress: ProgressWidget,
    advisories: AdvisoriesWidget,
    activity: ActivityWidget,
    notes: NotesWidget,
    ai: AiWidget,
    cycle: CycleWidget,
};
</script>

<template>
    <component :is="registry[widget]" v-if="registry[widget]" />
    <div v-else class="p-3 text-xs text-ink-faint">Unknown widget: {{ widget }}</div>
</template>
