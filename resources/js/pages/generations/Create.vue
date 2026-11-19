<script setup lang="ts">
import { HttpCancelledError } from '@inertiajs/core';
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { CircleAlert, ImagePlus, Info, Sparkles, Trash2 } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import ChoiceCards from '@/components/ChoiceCards.vue';
import type { Choice } from '@/components/ChoiceCards.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { compareUsd, formatTokens, formatUsd } from '@/lib/money';
import { index as creditsIndex } from '@/routes/credits';
import { create, estimate as estimateRoute, store } from '@/routes/generations';
import type { Estimate } from '@/types';

const props = defineProps<{
    // Sizes and qualities from Pixelforge's live price list, or null when
    // pricing can't be loaded right now.
    options: { sizes: string[]; qualities: string[] } | null;
    limits: { maxPromptLength: number; maxReferenceImages: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const page = usePage();

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const qualityHints: Record<string, string> = {
    low: 'Fastest, cheapest',
    medium: 'Balanced',
    high: 'Most detail',
};

function aspectOf(size: string): Choice['aspect'] {
    const [width, height] = size.split('x').map(Number);

    if (width === height) {
        return 'square';
    }

    return width > height ? 'landscape' : 'portrait';
}

const sizeChoices = computed<Choice[]>(() =>
    (props.options?.sizes ?? []).map((size) => ({
        value: size,
        label: sizeLabels[size] ?? size,
        hint: size.replace('x', ' × '),
        aspect: aspectOf(size),
    })),
);

const qualityChoices = computed<Choice[]>(() =>
    (props.options?.qualities ?? []).map((quality) => ({
        value: quality,
        label: quality.charAt(0).toUpperCase() + quality.slice(1),
        hint: qualityHints[quality],
    })),
);

const form = useForm({
    prompt: '',
    size: props.options?.sizes[0] ?? '',
    quality: props.options?.qualities.includes('medium')
        ? 'medium'
        : (props.options?.qualities[0] ?? ''),
    reference_images: [] as string[],
});

// Unicode characters, the way Pixelforge (and our validation) counts them.
const promptLength = computed(() => [...form.prompt].length);
const filledReferenceImages = computed(
    () => form.reference_images.filter((url) => url.trim() !== '').length,
);

// ---------------------------------------------------------------------
// Live estimate, computed by the server's PricingService.
// ---------------------------------------------------------------------

const estimateRequest = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_image_count: number;
    },
    Estimate
>({ prompt: '', size: '', quality: '', reference_image_count: 0 });

const estimate = ref<Estimate | null>(null);
const estimateFailed = ref(false);

async function refreshEstimate(): Promise<void> {
    if (!props.options) {
        return;
    }

    estimateRequest.cancel();
    estimateRequest.prompt = form.prompt;
    estimateRequest.size = form.size;
    estimateRequest.quality = form.quality;
    estimateRequest.reference_image_count = filledReferenceImages.value;
    estimateFailed.value = false;

    try {
        estimate.value = await estimateRequest.post(estimateRoute().url);
    } catch (error) {
        // A newer keystroke cancelled this request: not a failure.
        if (!(error instanceof HttpCancelledError)) {
            estimateFailed.value = true;
        }
    }
}

onMounted(refreshEstimate);

watchDebounced(
    () => [form.prompt, form.size, form.quality, filledReferenceImages.value],
    refreshEstimate,
    { debounce: 300 },
);

// ---------------------------------------------------------------------
// Credits: the maximum estimate must fit in what's available.
// ---------------------------------------------------------------------

const credits = computed(() => page.props.credits);

const cannotAfford = computed(
    () =>
        estimate.value !== null &&
        credits.value !== null &&
        (credits.value.available_usd.startsWith('-') ||
            compareUsd(credits.value.available_usd, estimate.value.max_usd) <
                0),
);

// Errors that aren't tied to a declared field ("credits", "pricing",
// "reference_images.2", ...).
const formErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);

// Bound rather than written inline: vue-tsc mis-reads the "//" of a URL in a
// static attribute on a component as a comment and loses the v-for scope.
const referenceImagePlaceholder = 'https://example.com/style.png';

function referenceImageError(index: number): string | undefined {
    return formErrors.value[`reference_images.${index}`];
}

function addReferenceImage(): void {
    if (form.reference_images.length < props.limits.maxReferenceImages) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
}

function submit(): void {
    form.post(store().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Generate an image"
            description="Describe the image you want. You'll see its price range before you submit, and you're only charged what it actually costs."
        />

        <Alert v-if="!options" variant="destructive">
            <CircleAlert />
            <AlertTitle>Pricing is unavailable right now</AlertTitle>
            <AlertDescription>
                PixelFlow couldn't load Pixelforge's live price list, so it
                can't price new generations. Please reload in a moment.
            </AlertDescription>
        </Alert>

        <Alert
            v-if="formErrors.credits || formErrors.pricing"
            variant="destructive"
            data-test="form-error"
        >
            <CircleAlert />
            <AlertTitle>The generation wasn't submitted</AlertTitle>
            <AlertDescription>
                {{ formErrors.credits ?? formErrors.pricing }}
                <Link
                    v-if="formErrors.credits"
                    :href="creditsIndex()"
                    class="font-medium underline underline-offset-4"
                    >Buy credits</Link
                >
            </AlertDescription>
        </Alert>

        <form
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]"
            @submit.prevent="submit"
        >
            <Card>
                <CardContent class="flex flex-col gap-6">
                    <!-- Prompt -->
                    <div class="grid gap-2">
                        <div class="flex items-baseline justify-between gap-2">
                            <Label for="prompt">Prompt</Label>
                            <span
                                :class="[
                                    'text-xs tabular-nums',
                                    promptLength > limits.maxPromptLength
                                        ? 'text-red-600'
                                        : 'text-muted-foreground',
                                ]"
                                >{{ formatTokens(promptLength) }} /
                                {{ formatTokens(limits.maxPromptLength) }}</span
                            >
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            v-focus
                            name="prompt"
                            rows="5"
                            required
                            :disabled="!options"
                            :aria-invalid="!!form.errors.prompt"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                        />
                        <InputError :message="form.errors.prompt" />
                    </div>

                    <!-- Size -->
                    <fieldset class="grid gap-2" :disabled="!options">
                        <legend class="mb-2 text-sm font-medium">Size</legend>
                        <ChoiceCards
                            v-model="form.size"
                            name="size"
                            :choices="sizeChoices"
                        />
                        <InputError :message="form.errors.size" />
                    </fieldset>

                    <!-- Quality -->
                    <fieldset class="grid gap-2" :disabled="!options">
                        <legend class="mb-2 text-sm font-medium">
                            Quality
                        </legend>
                        <ChoiceCards
                            v-model="form.quality"
                            name="quality"
                            :choices="qualityChoices"
                        />
                        <InputError :message="form.errors.quality" />
                    </fieldset>

                    <!-- Reference images -->
                    <div class="grid gap-2">
                        <div>
                            <p class="text-sm font-medium">
                                Reference images
                                <span class="font-normal text-muted-foreground"
                                    >(optional)</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Public image URLs that guide the style. Each one
                                is billed as input image tokens.
                            </p>
                        </div>

                        <div
                            v-for="(url, index) in form.reference_images"
                            :key="index"
                            class="grid gap-1"
                        >
                            <div class="flex gap-2">
                                <Input
                                    v-model="form.reference_images[index]"
                                    type="url"
                                    inputmode="url"
                                    :aria-label="
                                        'Reference image ' + (index + 1)
                                    "
                                    :placeholder="referenceImagePlaceholder"
                                    :aria-invalid="!!referenceImageError(index)"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        'Remove reference image ' + (index + 1)
                                    "
                                    @click="removeReferenceImage(index)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                            <InputError :message="referenceImageError(index)" />
                        </div>

                        <Button
                            v-if="
                                form.reference_images.length <
                                limits.maxReferenceImages
                            "
                            type="button"
                            variant="outline"
                            size="sm"
                            class="w-fit"
                            :disabled="!options"
                            @click="addReferenceImage"
                        >
                            <ImagePlus />
                            Add reference image
                        </Button>
                        <InputError :message="form.errors.reference_images" />
                    </div>
                </CardContent>
            </Card>

            <!-- Price and submit -->
            <aside class="lg:sticky lg:top-4">
                <Card data-test="estimate-panel">
                    <CardHeader>
                        <CardTitle>Estimated cost</CardTitle>
                        <CardDescription>
                            Part of the price is only known once the image is
                            done, so this is a range.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div aria-live="polite">
                            <div
                                v-if="estimateFailed"
                                class="flex flex-col gap-2 text-sm"
                            >
                                <p class="text-red-600 dark:text-red-400">
                                    Couldn't calculate the price.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="w-fit"
                                    @click="refreshEstimate"
                                    >Try again</Button
                                >
                            </div>
                            <div v-else-if="estimate" class="space-y-1">
                                <p
                                    :class="[
                                        'font-mono text-xl font-semibold break-all',
                                        estimateRequest.processing
                                            ? 'opacity-50'
                                            : '',
                                    ]"
                                    data-test="estimate-range"
                                >
                                    {{ formatUsd(estimate.min_usd) }} –
                                    {{ formatUsd(estimate.max_usd) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    You're charged the actual cost when it
                                    finishes, never more than
                                    {{ formatUsd(estimate.max_usd) }}. Failed
                                    generations are free.
                                </p>
                            </div>
                            <div v-else-if="options" class="space-y-2">
                                <Skeleton class="h-7 w-48" />
                                <Skeleton class="h-4 w-full" />
                            </div>
                        </div>

                        <dl
                            v-if="estimate && !estimateFailed"
                            class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 border-t pt-3 text-xs"
                        >
                            <dt class="text-muted-foreground">
                                Prompt text tokens
                            </dt>
                            <dd class="text-right font-mono">
                                {{
                                    formatTokens(
                                        estimate.tokens.input_text.min,
                                    )
                                }}–{{
                                    formatTokens(estimate.tokens.input_text.max)
                                }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Reference image tokens
                            </dt>
                            <dd class="text-right font-mono">
                                {{ formatTokens(estimate.tokens.input_image) }}
                            </dd>
                            <dt class="text-muted-foreground">Output tokens</dt>
                            <dd class="text-right font-mono">
                                {{ formatTokens(estimate.tokens.output) }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Reasoning tokens
                            </dt>
                            <dd class="text-right font-mono">
                                {{
                                    formatTokens(estimate.tokens.reasoning.min)
                                }}–{{
                                    formatTokens(estimate.tokens.reasoning.max)
                                }}
                            </dd>
                        </dl>

                        <div
                            v-if="credits"
                            class="flex items-center justify-between rounded-md bg-muted px-3 py-2 text-sm"
                        >
                            <span class="text-muted-foreground"
                                >Available credits</span
                            >
                            <Link
                                :href="creditsIndex()"
                                class="font-mono font-medium hover:underline"
                                >{{ formatUsd(credits.available_usd) }}</Link
                            >
                        </div>

                        <p
                            v-if="cannotAfford"
                            class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                            data-test="insufficient-credits"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                This could cost up to
                                {{ formatUsd(estimate?.max_usd) }}, more than
                                your available credits.
                                <Link
                                    :href="creditsIndex()"
                                    class="font-medium underline underline-offset-4"
                                    >Buy credits</Link
                                >
                            </span>
                        </p>

                        <Button
                            type="submit"
                            size="lg"
                            class="w-full"
                            :disabled="
                                !options ||
                                form.processing ||
                                cannotAfford ||
                                form.prompt.trim() === ''
                            "
                            data-test="generate-button"
                        >
                            <Spinner v-if="form.processing" />
                            <Sparkles v-else />
                            Generate
                        </Button>
                    </CardContent>
                </Card>
            </aside>
        </form>
    </div>
</template>
