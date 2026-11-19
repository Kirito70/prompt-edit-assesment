<script setup lang="ts">
import { Deferred, Head, Link } from "@inertiajs/vue3";
import { Banknote, Coins, Image, TrendingUp, X } from "@lucide/vue";
import { computed } from "vue";
import AttentionPanel from "@/components/admin/AttentionPanel.vue";
import DateRangeFilter from "@/components/admin/DateRangeFilter.vue";
import MoneyTable from "@/components/admin/MoneyTable.vue";
import ReconciliationPanel from "@/components/admin/ReconciliationPanel.vue";
import StatCard from "@/components/dashboard/StatCard.vue";
import Heading from "@/components/Heading.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { formatCost } from "@/lib/generation";
import { dashboard } from "@/routes/admin";
import type {
    AdminAttention,
    AdminDailyRow,
    AdminFilters,
    AdminSummary,
    AdminUserRow,
    Reconciliation,
} from "@/types";

const props = defineProps<{
    filters: AdminFilters;
    summary: AdminSummary;
    daily: AdminDailyRow[];
    users: AdminUserRow[];
    attention: AdminAttention;
    reconciliation?: Reconciliation;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Admin", href: dashboard() }],
    },
});

// Newest day first reads best in a table.
const dailyRows = computed(() =>
    [...props.daily].reverse().map((row) => ({ ...row, key: row.date })),
);
const userRows = computed(() =>
    props.users.map((row) => ({ ...row, key: row.id })),
);

const attentionCount = computed(
    () => props.attention.stuck_total + props.attention.events_unprocessed,
);

const userLink = (id: number): string =>
    dashboard.url({
        query: { start: props.filters.start, end: props.filters.end, user: id },
    });

const clearUserLink = computed(() =>
    dashboard.url({
        query: { start: props.filters.start, end: props.filters.end },
    }),
);
</script>

<template>
    <Head title="Admin" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Admin"
            description="Credits charged, provider cost and profit, per day and per user"
        />

        <DateRangeFilter
            :start="filters.start"
            :end="filters.end"
            :user-id="filters.user?.id ?? null"
        />

        <div
            v-if="filters.user"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-sidebar-border/70 px-4 py-2 text-sm dark:border-sidebar-border"
        >
            <span>
                Showing only
                <strong>{{ filters.user.name }}</strong>
                ({{ filters.user.email }}). Reconciliation always covers
                everyone.
            </span>
            <Button as-child variant="outline" size="sm" class="min-h-11">
                <Link :href="clearUserLink">
                    <X class="size-4" aria-hidden="true" />
                    Show everyone
                </Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Credits charged"
                :value="formatCost(summary.credits)"
                :icon="Coins"
                hint="What users paid."
            />
            <StatCard
                label="Provider cost"
                :value="formatCost(summary.provider_cost)"
                :icon="Banknote"
                hint="What Pixelforge billed us."
            />
            <StatCard
                label="Profit"
                :value="formatCost(summary.profit)"
                :icon="TrendingUp"
                hint="Credits charged minus provider cost."
            />
            <StatCard
                label="Generations"
                :value="summary.generations.toLocaleString()"
                :icon="Image"
                hint="Billed (succeeded). Failed ones cost nothing."
            />
        </div>

        <Tabs default-value="overview" class="gap-4">
            <TabsList class="h-auto flex-wrap">
                <TabsTrigger value="overview" class="min-h-11 px-4"
                    >Per day</TabsTrigger
                >
                <TabsTrigger value="users" class="min-h-11 px-4"
                    >Per user</TabsTrigger
                >
                <TabsTrigger value="reconciliation" class="min-h-11 px-4"
                    >Reconciliation</TabsTrigger
                >
                <TabsTrigger value="attention" class="min-h-11 gap-2 px-4">
                    Needs attention
                    <Badge v-if="attentionCount > 0" variant="destructive">
                        {{ attentionCount }}
                    </Badge>
                </TabsTrigger>
            </TabsList>

            <TabsContent value="overview">
                <MoneyTable
                    caption="Credits, provider cost and profit per UTC day"
                    first-column="Day (UTC)"
                    :rows="dailyRows"
                >
                    <template #label="{ row }">
                        <span class="tabular-nums">{{ row.date }}</span>
                    </template>
                </MoneyTable>
                <p
                    class="mt-2 max-w-prose text-xs text-muted-foreground text-pretty"
                >
                    A generation counts on the UTC day it completed, which is
                    the day Pixelforge bills it.
                </p>
            </TabsContent>

            <TabsContent value="users">
                <p
                    v-if="userRows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No billed activity in this range.
                </p>
                <MoneyTable
                    v-else
                    caption="Credits, provider cost and profit per user, biggest spender first"
                    first-column="User"
                    :rows="userRows"
                >
                    <template #label="{ row }">
                        <div>{{ row.name }}</div>
                        <div class="text-xs font-normal text-muted-foreground">
                            {{ row.email }}
                        </div>
                    </template>
                    <template #action="{ row }">
                        <Link
                            :href="userLink(row.id)"
                            class="inline-flex min-h-11 items-center rounded-md px-2 text-sm underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="`View daily breakdown for ${row.name}`"
                        >
                            Days
                        </Link>
                    </template>
                </MoneyTable>
            </TabsContent>

            <TabsContent value="reconciliation">
                <Deferred data="reconciliation">
                    <template #fallback>
                        <div class="flex flex-col gap-3" role="status">
                            <Skeleton class="h-5 w-64" />
                            <Skeleton class="h-48 w-full" />
                            <span class="sr-only"
                                >Asking Pixelforge for its usage…</span
                            >
                        </div>
                    </template>

                    <ReconciliationPanel
                        v-if="reconciliation"
                        :data="reconciliation"
                    />
                </Deferred>
            </TabsContent>

            <TabsContent value="attention">
                <AttentionPanel :attention="attention" />
            </TabsContent>
        </Tabs>
    </div>
</template>
