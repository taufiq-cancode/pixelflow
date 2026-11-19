<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { formatUsd, isNegative } from '@/lib/money';
import { index as creditsIndex } from '@/routes/credits';

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <SidebarGroup v-if="credits" class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton
                    as-child
                    size="lg"
                    tooltip="Credits"
                    data-test="sidebar-credits"
                >
                    <Link :href="creditsIndex()">
                        <Wallet />
                        <div class="grid flex-1 text-left leading-tight">
                            <span class="text-xs text-muted-foreground"
                                >Available credits</span
                            >
                            <span
                                :class="[
                                    'truncate font-mono text-sm font-semibold',
                                    isNegative(credits.available_usd)
                                        ? 'text-red-600 dark:text-red-400'
                                        : '',
                                ]"
                                >{{ formatUsd(credits.available_usd) }}</span
                            >
                        </div>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
