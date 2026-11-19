<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- PixelFlow mark: four "pixels" fading along the flow. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="2" y="2" width="9" height="9" rx="2" />
        <rect x="13" y="2" width="9" height="9" rx="2" opacity="0.7" />
        <rect x="2" y="13" width="9" height="9" rx="2" opacity="0.7" />
        <rect x="13" y="13" width="9" height="9" rx="2" opacity="0.4" />
    </svg>
</template>
