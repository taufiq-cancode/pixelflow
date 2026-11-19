<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, isNegative } from '@/lib/money';
import { index, store } from '@/routes/credits';

defineProps<{
    packs: number[];
    purchases: { id: number; amount_usd: string; created_at: string | null }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const page = usePage();
const credits = computed(() => page.props.credits);

const form = useForm({ amount: 0 });

function buy(amount: number): void {
    form.amount = amount;
    form.post(store().url, { preserveScroll: true });
}

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
}
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Credits"
            description="Credits are US dollars. Each generation is charged its exact cost when it finishes."
        />

        <div v-if="credits" class="grid gap-4 sm:grid-cols-3">
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Available to spend</CardDescription>
                </CardHeader>
                <CardContent class="px-4">
                    <p
                        :class="[
                            'font-mono text-2xl font-semibold break-all',
                            isNegative(credits.available_usd)
                                ? 'text-red-600 dark:text-red-400'
                                : '',
                        ]"
                        data-test="available-credits"
                    >
                        {{ formatUsd(credits.available_usd) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Balance</CardDescription>
                </CardHeader>
                <CardContent class="px-4">
                    <p class="font-mono text-2xl font-semibold break-all">
                        {{ formatUsd(credits.balance_usd) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription
                        >Reserved by unfinished generations</CardDescription
                    >
                </CardHeader>
                <CardContent class="px-4">
                    <p class="font-mono text-2xl font-semibold break-all">
                        {{ formatUsd(credits.reserved_usd) }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Wallet class="size-5" />
                    Buy credits
                </CardTitle>
                <CardDescription>
                    A demo purchase: the credits are added instantly and no
                    payment is taken.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <div class="grid grid-cols-3 gap-3">
                    <Button
                        v-for="pack in packs"
                        :key="pack"
                        variant="outline"
                        size="lg"
                        :disabled="form.processing"
                        :data-test="`buy-${pack}`"
                        @click="buy(pack)"
                    >
                        <Spinner
                            v-if="form.processing && form.amount === pack"
                        />
                        Add ${{ pack }}
                    </Button>
                </div>
                <InputError :message="form.errors.amount" />
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>How you're charged</CardTitle>
            </CardHeader>
            <CardContent>
                <ul
                    class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                >
                    <li>
                        Before you submit, you see a price range. The top of
                        that range is reserved from your available credits while
                        the image is generated.
                    </li>
                    <li>
                        When it finishes, you're charged Pixelforge's exact cost
                        plus 25%, which is never more than the top of the range,
                        and the reservation is released.
                    </li>
                    <li>If a generation fails, it's free.</li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Purchases</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="purchases.length === 0"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <CircleAlert class="size-4" />
                    No credits bought yet. Add some above to start generating.
                </p>
                <ul v-else class="divide-y text-sm">
                    <li
                        v-for="purchase in purchases"
                        :key="purchase.id"
                        class="flex items-center justify-between py-2"
                    >
                        <span class="text-muted-foreground">{{
                            formatDate(purchase.created_at)
                        }}</span>
                        <span class="font-mono font-medium"
                            >+{{ formatUsd(purchase.amount_usd) }}</span
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
