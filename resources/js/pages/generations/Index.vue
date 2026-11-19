<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, ImageIcon, Sparkles } from '@lucide/vue';
import { watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    // True while any of the user's generations is unfinished.
    hasInFlight: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

// Live status: reload just the list (and the balance) every 3 seconds, but
// only while something is still queued or processing.
const { start, stop, polling } = usePoll(
    3000,
    { only: ['generations', 'hasInFlight', 'credits'] },
    { autoStart: false },
);

watch(
    () => props.hasInFlight,
    (inFlight) => (inFlight ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="History" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your generations, newest first, with what each one was estimated to cost and what it actually cost."
            />
            <Button as-child>
                <Link :href="create()">
                    <Sparkles />
                    New generation
                </Link>
            </Button>
        </div>

        <p
            v-if="polling"
            class="-mt-4 flex items-center gap-2 text-xs text-muted-foreground"
            aria-live="polite"
        >
            <span class="relative flex size-2">
                <span
                    class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"
                />
                <span
                    class="relative inline-flex size-2 rounded-full bg-emerald-500"
                />
            </span>
            Updating live while generations are in progress
        </p>

        <Card v-if="generations.data.length === 0" data-test="empty-state">
            <CardContent
                class="flex flex-col items-center gap-3 py-10 text-center"
            >
                <ImageIcon class="size-10 text-muted-foreground" />
                <div>
                    <p class="font-medium">No generations yet</p>
                    <p class="text-sm text-muted-foreground">
                        Describe an image and it will show up here with its live
                        status and cost.
                    </p>
                </div>
                <Button as-child>
                    <Link :href="create()">Generate your first image</Link>
                </Button>
            </CardContent>
        </Card>

        <div v-else class="flex flex-col gap-4">
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between gap-4"
            aria-label="Pagination"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.prev"
                :as-child="!!generations.links.prev"
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                >
                    <ChevronLeft />
                    Newer
                </Link>
                <template v-else>
                    <ChevronLeft />
                    Newer
                </template>
            </Button>
            <span class="text-sm text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.next"
                :as-child="!!generations.links.next"
            >
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                >
                    Older
                    <ChevronRight />
                </Link>
                <template v-else>
                    Older
                    <ChevronRight />
                </template>
            </Button>
        </nav>
    </div>
</template>
