<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import {
    CircleCheck,
    CircleX,
    LoaderCircle,
    Sparkles,
    Wallet,
    CalendarDays,
} from "@lucide/vue";
import { computed } from "vue";
import RecentGenerations from "@/components/dashboard/RecentGenerations.vue";
import SpendChart from "@/components/dashboard/SpendChart.vue";
import StatCard from "@/components/dashboard/StatCard.vue";
import Heading from "@/components/Heading.vue";
import { Button } from "@/components/ui/button";
import { formatCost } from "@/lib/generation";
import { dashboard, generate, history } from "@/routes";
import type { DailySpend, DashboardStats, GenerationRow } from "@/types";

const props = defineProps<{
    stats: DashboardStats;
    dailySpend: DailySpend[];
    recent: GenerationRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Dashboard", href: dashboard() }],
    },
});

const isNew = computed(() => props.recent.length === 0);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Dashboard"
                description="What you've spent and what's happening now"
            />
            <div class="flex gap-2">
                <Button as-child variant="outline" class="min-h-11">
                    <Link :href="history()">View history</Link>
                </Button>
                <Button as-child class="min-h-11">
                    <Link :href="generate()">
                        <Sparkles class="size-4" aria-hidden="true" />
                        Generate image
                    </Link>
                </Button>
            </div>
        </div>

        <section
            v-if="isNew"
            class="flex flex-col items-start gap-3 rounded-xl border border-dashed border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <h2 class="text-base font-semibold">Create your first image</h2>
            <p class="max-w-prose text-sm text-muted-foreground text-pretty">
                Describe what you want and you'll see the price before you
                submit. Failed generations cost nothing.
            </p>
            <Button as-child class="min-h-11">
                <Link :href="generate()">
                    <Sparkles class="size-4" aria-hidden="true" />
                    Generate image
                </Link>
            </Button>
        </section>

        <!-- Announced politely when live updates change the numbers. -->
        <div
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
            aria-live="polite"
        >
            <StatCard
                label="Total spent"
                :value="formatCost(stats.total_spent)"
                :icon="Wallet"
            />
            <StatCard
                label="This month"
                :value="formatCost(stats.spent_this_month)"
                :icon="CalendarDays"
            />
            <StatCard
                label="Images generated"
                :value="stats.images.toLocaleString()"
                :icon="CircleCheck"
            />
            <StatCard
                label="In progress"
                :value="stats.in_progress.toLocaleString()"
                :icon="LoaderCircle"
            />
            <StatCard
                label="Failed"
                :value="stats.failed.toLocaleString()"
                :icon="CircleX"
                hint="Failed generations cost nothing."
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <SpendChart :days="dailySpend" />
            <RecentGenerations v-if="!isNew" :rows="recent" />
        </div>
    </div>
</template>
