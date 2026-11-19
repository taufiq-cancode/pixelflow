<script setup lang="ts">
import { CircleX, ImageOff, Link2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatTokens, formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const imageFailedToLoad = ref(false);

const showsImage = computed(
    () => !!props.generation.image_url && !imageFailedToLoad.value,
);

// Keep the preview in the image's real proportions. Without an image, use a
// compact strip on phones instead of an empty square. (Full class names, so
// Tailwind can find them.)
const previewClass = computed(() => {
    const [width, height] = props.generation.size.split('x').map(Number);
    const shape =
        width > height ? 'landscape' : width < height ? 'portrait' : 'square';

    const classes = {
        landscape: ['aspect-[3/2]', 'h-24 sm:h-auto sm:aspect-[3/2]'],
        portrait: ['aspect-[2/3]', 'h-24 sm:h-auto sm:aspect-[2/3]'],
        square: ['aspect-square', 'h-24 sm:h-auto sm:aspect-square'],
    }[shape];

    return showsImage.value ? classes[0] : classes[1];
});

const createdAt = computed(() =>
    props.generation.created_at
        ? new Date(props.generation.created_at).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '',
);

const isPending = computed(
    () =>
        props.generation.status === 'queued' ||
        props.generation.status === 'processing',
);
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0" data-test="generation-card">
        <div class="grid sm:grid-cols-[minmax(0,220px)_1fr]">
            <!-- Preview -->
            <div class="flex items-center justify-center bg-muted p-3">
                <div
                    :class="[
                        previewClass,
                        'relative flex max-h-72 w-full max-w-[220px] items-center justify-center overflow-hidden rounded-md bg-background/60',
                    ]"
                >
                    <a
                        v-if="showsImage && generation.image_url"
                        :href="generation.image_url"
                        target="_blank"
                        rel="noopener"
                        class="block size-full"
                        title="Open the full-size image"
                    >
                        <img
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            loading="lazy"
                            class="size-full object-cover"
                            @error="imageFailedToLoad = true"
                        />
                    </a>
                    <div
                        v-else-if="isPending"
                        class="flex flex-col items-center gap-2 px-2 text-center text-xs text-muted-foreground"
                    >
                        <Spinner class="size-6" />
                        <span v-if="generation.waiting_for_slot">
                            Waiting for Pixelforge to accept it
                        </span>
                        <span v-else-if="generation.status === 'queued'">
                            In Pixelforge's queue
                        </span>
                        <span v-else>Generating your image</span>
                    </div>
                    <div
                        v-else-if="generation.status === 'failed'"
                        class="flex flex-col items-center gap-2 px-2 text-center text-xs text-muted-foreground"
                    >
                        <CircleX class="size-6 text-red-500" />
                        <span>No image</span>
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 px-2 text-center text-xs text-muted-foreground"
                    >
                        <ImageOff class="size-6" />
                        <span>
                            Image link expired.<br />Pixelforge keeps images for
                            24 hours.
                        </span>
                    </div>
                </div>
            </div>

            <!-- Details -->
            <div class="flex min-w-0 flex-col gap-3 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <GenerationStatusBadge :generation="generation" />
                    <time
                        v-if="generation.created_at"
                        :datetime="generation.created_at"
                        class="text-xs text-muted-foreground"
                        >{{ createdAt }}</time
                    >
                </div>

                <p class="line-clamp-3 text-sm break-words">
                    {{ generation.prompt }}
                </p>

                <div
                    class="flex flex-wrap gap-1.5 text-xs text-muted-foreground"
                >
                    <span class="rounded bg-muted px-1.5 py-0.5">{{
                        generation.size.replace('x', ' × ')
                    }}</span>
                    <span class="rounded bg-muted px-1.5 py-0.5 capitalize">{{
                        generation.quality
                    }}</span>
                    <span
                        v-if="generation.reference_images.length > 0"
                        class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5"
                    >
                        <Link2 class="size-3" />
                        {{ generation.reference_images.length }} reference
                        {{
                            generation.reference_images.length === 1
                                ? 'image'
                                : 'images'
                        }}
                    </span>
                </div>

                <dl
                    class="grid grid-cols-2 gap-3 rounded-md border p-3 text-sm"
                >
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Estimated</dt>
                        <!-- Each amount stays whole; the line may wrap at the dash. -->
                        <dd class="font-mono text-xs sm:text-sm">
                            <span class="whitespace-nowrap">{{
                                formatUsd(generation.estimated_min_usd)
                            }}</span>
                            –
                            <span class="whitespace-nowrap">{{
                                formatUsd(generation.estimated_max_usd)
                            }}</span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">
                            Actual cost
                        </dt>
                        <dd
                            v-if="generation.status === 'succeeded'"
                            class="font-mono text-xs font-semibold whitespace-nowrap sm:text-sm"
                            data-test="actual-cost"
                        >
                            {{ formatUsd(generation.actual_cost_usd) }}
                        </dd>
                        <dd
                            v-else-if="generation.status === 'failed'"
                            class="text-xs sm:text-sm"
                            data-test="actual-cost"
                        >
                            <span class="font-mono font-semibold">$0.00</span>
                            <span class="text-muted-foreground">
                                · not charged</span
                            >
                        </dd>
                        <dd
                            v-else
                            class="text-xs text-muted-foreground sm:text-sm"
                            data-test="actual-cost"
                        >
                            Known when it finishes
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="
                        generation.status === 'failed' &&
                        generation.error_message
                    "
                    class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
                    role="alert"
                >
                    {{ generation.error_message }}
                </p>

                <details v-if="generation.usage" class="text-xs">
                    <summary
                        class="cursor-pointer text-muted-foreground select-none hover:text-foreground"
                    >
                        Token usage
                    </summary>
                    <dl
                        class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4"
                    >
                        <div>
                            <dt class="text-muted-foreground">Prompt text</dt>
                            <dd class="font-mono">
                                {{ formatTokens(generation.usage.input_text) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Reference images
                            </dt>
                            <dd class="font-mono">
                                {{ formatTokens(generation.usage.input_image) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Output</dt>
                            <dd class="font-mono">
                                {{ formatTokens(generation.usage.output) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Reasoning</dt>
                            <dd class="font-mono">
                                {{ formatTokens(generation.usage.reasoning) }}
                            </dd>
                        </div>
                    </dl>
                </details>
            </div>
        </div>
    </Card>
</template>
