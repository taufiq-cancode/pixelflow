<script setup lang="ts">
/**
 * A row of radio buttons styled as selectable cards (used for the image
 * size and quality). Keyboard accessible: the native radio is only hidden
 * visually.
 */
export type Choice = {
    value: string;
    label: string;
    hint?: string;
    // Draws a small outline in the image's proportions.
    aspect?: 'square' | 'landscape' | 'portrait';
};

const model = defineModel<string>({ required: true });

defineProps<{
    name: string;
    choices: Choice[];
}>();

const aspectClasses = {
    square: 'size-7',
    landscape: 'h-6 w-9',
    portrait: 'h-9 w-6',
};
</script>

<template>
    <div class="grid grid-cols-3 gap-2">
        <label
            v-for="choice in choices"
            :key="choice.value"
            :class="[
                'flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-3 text-center transition-colors has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 has-disabled:cursor-not-allowed has-disabled:opacity-50',
                model === choice.value
                    ? 'border-primary bg-accent'
                    : 'hover:bg-accent/50',
            ]"
            :data-test="`${name}-${choice.value}`"
        >
            <input
                v-model="model"
                type="radio"
                :name="name"
                :value="choice.value"
                class="sr-only"
            />
            <span
                v-if="choice.aspect"
                aria-hidden="true"
                :class="[
                    'mb-1 rounded-sm border-2 border-current opacity-60',
                    aspectClasses[choice.aspect],
                ]"
            />
            <span class="text-sm font-medium">{{ choice.label }}</span>
            <span v-if="choice.hint" class="text-xs text-muted-foreground">{{
                choice.hint
            }}</span>
        </label>
    </div>
</template>
