<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { formatTokens, formatUsd, isNegative } from '@/lib/money';
import { usage } from '@/routes/admin';
import type { UsageDay, UsageReport } from '@/types';

const props = defineProps<{
    report: UsageReport;
    errors?: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usage report', href: usage() }],
    },
});

const start = ref(props.report.start);
const end = ref(props.report.end);

function applyRange(): void {
    router.get(
        usage().url,
        { start: start.value, end: end.value },
        { preserveState: true, preserveScroll: true },
    );
}

// Days with activity on either side; empty days are hidden.
const activeDays = computed(() =>
    props.report.days.filter(
        (day) => day.generations > 0 || (day.pixelforge?.generations ?? 0) > 0,
    ),
);

const tokenFields = [
    ['input_text_tokens', 'Text'],
    ['input_image_tokens', 'Image'],
    ['output_tokens', 'Output'],
    ['reasoning_tokens', 'Reasoning'],
] as const;

function mismatchDetails(day: UsageDay): string[] {
    if (!day.pixelforge) {
        return [];
    }

    const details: string[] = [
        `generations ${day.generations} vs ${day.pixelforge.generations}`,
        `cost ${formatUsd(day.provider_usd)} vs ${formatUsd(day.pixelforge.cost_usd)}`,
    ];

    for (const [field, label] of tokenFields) {
        details.push(
            `${label.toLowerCase()} tokens ${formatTokens(day[field])} vs ${formatTokens(day.pixelforge[field])}`,
        );
    }

    return details;
}
</script>

<template>
    <Head title="Usage report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Usage report"
            description="Credit spend (what users were charged), provider cost (what Pixelforge charged us) and profit per UTC day, reconciled against Pixelforge's GET /v1/usage for this environment's API key."
        />

        <!-- Range -->
        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyRange"
        >
            <div class="grid gap-1.5">
                <Label for="start">From (UTC)</Label>
                <Input id="start" v-model="start" type="date" class="w-40" />
            </div>
            <div class="grid gap-1.5">
                <Label for="end">To (UTC)</Label>
                <Input id="end" v-model="end" type="date" class="w-40" />
            </div>
            <Button type="submit" variant="outline">Update</Button>
            <InputError
                class="basis-full"
                :message="errors?.end ?? errors?.start"
            />
        </form>

        <!-- Reconciliation status -->
        <Alert
            v-if="report.reconciled === true"
            class="border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
            data-test="reconciled"
        >
            <CircleCheck />
            <AlertTitle>Every day matches Pixelforge exactly</AlertTitle>
            <AlertDescription class="text-emerald-800 dark:text-emerald-200">
                Generation counts, every token count and the provider cost (to
                the nanodollar) equal what Pixelforge reports.
            </AlertDescription>
        </Alert>
        <Alert
            v-else-if="report.reconciled === false"
            variant="destructive"
            data-test="not-reconciled"
        >
            <CircleX />
            <AlertTitle>Some days differ from Pixelforge</AlertTitle>
            <AlertDescription>
                Rows marked ✗ below don't match. A generation that finished in
                the last minute or two may not be recorded yet; the reconciler
                catches up within a few minutes.
            </AlertDescription>
        </Alert>
        <Alert v-else variant="destructive">
            <CircleAlert />
            <AlertTitle>Couldn't reconcile with Pixelforge</AlertTitle>
            <AlertDescription>{{ report.pixelforge_error }}</AlertDescription>
        </Alert>

        <!-- Totals -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Credit spend (users)</CardDescription>
                </CardHeader>
                <CardContent
                    class="px-4 font-mono text-xl font-semibold break-all"
                >
                    {{ formatUsd(report.totals.charged_usd) }}
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Provider cost (ours)</CardDescription>
                </CardHeader>
                <CardContent
                    class="px-4 font-mono text-xl font-semibold break-all"
                >
                    {{ formatUsd(report.totals.provider_usd) }}
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Pixelforge /v1/usage</CardDescription>
                </CardHeader>
                <CardContent
                    class="px-4 font-mono text-xl font-semibold break-all"
                >
                    {{ formatUsd(report.totals.pixelforge_cost_usd) }}
                </CardContent>
            </Card>
            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Profit</CardDescription>
                </CardHeader>
                <CardContent
                    :class="[
                        'px-4 font-mono text-xl font-semibold break-all',
                        isNegative(report.totals.profit_usd)
                            ? 'text-red-600 dark:text-red-400'
                            : '',
                    ]"
                >
                    {{ formatUsd(report.totals.profit_usd) }}
                </CardContent>
            </Card>
        </div>

        <!-- Per day -->
        <Card>
            <CardHeader>
                <CardTitle>Per day</CardTitle>
                <CardDescription>
                    Generations are billed on the UTC day they completed. Days
                    with no usage on either side are hidden.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="activeDays.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No billed generations in this range.
                </p>
                <div v-else class="overflow-x-auto">
                    <table
                        class="w-full min-w-[760px] text-sm"
                        data-test="days-table"
                    >
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-3 font-medium">Date</th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Generations<br />ours / Pixelforge
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Credit spend
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Provider cost
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Pixelforge cost_usd
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Profit
                                </th>
                                <th class="py-2 text-center font-medium">
                                    Match
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="day in activeDays" :key="day.date">
                                <tr class="border-b last:border-0">
                                    <td
                                        class="py-2 pr-3 font-medium whitespace-nowrap"
                                    >
                                        {{ day.date }}
                                    </td>
                                    <td class="py-2 pr-3 text-right font-mono">
                                        {{ day.generations }} /
                                        {{ day.pixelforge?.generations ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 text-right font-mono">
                                        {{ formatUsd(day.charged_usd) }}
                                    </td>
                                    <td class="py-2 pr-3 text-right font-mono">
                                        {{ formatUsd(day.provider_usd) }}
                                    </td>
                                    <td class="py-2 pr-3 text-right font-mono">
                                        {{
                                            formatUsd(day.pixelforge?.cost_usd)
                                        }}
                                    </td>
                                    <td
                                        :class="[
                                            'py-2 pr-3 text-right font-mono',
                                            isNegative(day.profit_usd)
                                                ? 'text-red-600 dark:text-red-400'
                                                : '',
                                        ]"
                                    >
                                        {{ formatUsd(day.profit_usd) }}
                                    </td>
                                    <td class="py-2 text-center">
                                        <CircleCheck
                                            v-if="day.matches === true"
                                            class="mx-auto size-4 text-emerald-600"
                                            aria-label="Matches Pixelforge"
                                        />
                                        <CircleX
                                            v-else-if="day.matches === false"
                                            class="mx-auto size-4 text-red-600"
                                            aria-label="Differs from Pixelforge"
                                        />
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                    </td>
                                </tr>
                                <tr
                                    v-if="day.matches === false"
                                    class="border-b"
                                >
                                    <td
                                        colspan="7"
                                        class="pb-2 text-xs text-red-700 dark:text-red-300"
                                    >
                                        Ours vs Pixelforge:
                                        {{ mismatchDetails(day).join(' · ') }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t font-semibold">
                                <td class="py-2 pr-3">Total</td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ report.totals.generations }} /
                                    {{
                                        report.totals.pixelforge_generations ??
                                        '—'
                                    }}
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ formatUsd(report.totals.charged_usd) }}
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ formatUsd(report.totals.provider_usd) }}
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{
                                        formatUsd(
                                            report.totals.pixelforge_cost_usd,
                                        )
                                    }}
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ formatUsd(report.totals.profit_usd) }}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </CardContent>
        </Card>

        <!-- Per user -->
        <Card>
            <CardHeader>
                <CardTitle>Per day and user</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="report.users.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No billed generations in this range.
                </p>
                <div v-else class="overflow-x-auto">
                    <table
                        class="w-full min-w-[640px] text-sm"
                        data-test="users-table"
                    >
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-3 font-medium">Date</th>
                                <th class="py-2 pr-3 font-medium">User</th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Generations
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Credit spend
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Provider cost
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in report.users"
                                :key="`${row.date}-${row.user_id}`"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    {{ row.date }}
                                </td>
                                <td class="py-2 pr-3">
                                    <div class="font-medium">
                                        {{ row.name }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ row.email }}
                                    </div>
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ row.generations }}
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ formatUsd(row.charged_usd) }}
                                </td>
                                <td class="py-2 pr-3 text-right font-mono">
                                    {{ formatUsd(row.provider_usd) }}
                                </td>
                                <td
                                    :class="[
                                        'py-2 text-right font-mono',
                                        isNegative(row.profit_usd)
                                            ? 'text-red-600 dark:text-red-400'
                                            : '',
                                    ]"
                                >
                                    {{ formatUsd(row.profit_usd) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
