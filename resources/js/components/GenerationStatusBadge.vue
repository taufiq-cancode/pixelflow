<script setup lang="ts">
import { CircleCheck, CircleX, Clock, Hourglass } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Pick<Generation, 'status' | 'waiting_for_slot'>;
}>();

const appearance = computed(() => {
    switch (props.generation.status) {
        case 'processing':
            return {
                label: 'Processing',
                class: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                class: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
            };
        case 'failed':
            return {
                label: 'Failed',
                class: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
            };
        default:
            return {
                label: props.generation.waiting_for_slot
                    ? 'Queued · waiting for a free slot'
                    : 'Queued',
                class: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
            };
    }
});
</script>

<template>
    <Badge :class="appearance.class" data-test="status-badge">
        <Spinner v-if="generation.status === 'processing'" class="size-3" />
        <CircleCheck v-else-if="generation.status === 'succeeded'" />
        <CircleX v-else-if="generation.status === 'failed'" />
        <Hourglass v-else-if="generation.waiting_for_slot" />
        <Clock v-else />
        {{ appearance.label }}
    </Badge>
</template>
