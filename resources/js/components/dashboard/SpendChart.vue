<script setup lang="ts">
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    LinearScale,
    Tooltip,
} from "chart.js";
import type { ChartData, ChartOptions } from "chart.js";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Bar } from "vue-chartjs";
import { formatCost } from "@/lib/generation";
import type { DailySpend } from "@/types";

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip);

const props = defineProps<{ days: DailySpend[] }>();

const colors = ref({ bar: "", grid: "", text: "" });

// Theme colours live in CSS variables (OKLCH); resolve them to rgba for the canvas.
const resolve = (name: string): string => {
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();
    const ctx = document
        .createElement("canvas")
        .getContext("2d", { willReadFrequently: true });

    if (!ctx || value === "") {
        return value;
    }

    ctx.fillStyle = value;
    ctx.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;

    return `rgba(${r}, ${g}, ${b}, ${(a / 255).toFixed(3)})`;
};

const readColors = (): void => {
    colors.value = {
        bar: resolve("--chart-spend"),
        grid: resolve("--border"),
        text: resolve("--muted-foreground"),
    };
};

let observer: MutationObserver | null = null;

onMounted(() => {
    readColors();
    // Re-read when light/dark mode switches (the `dark` class on <html>).
    observer = new MutationObserver(readColors);
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ["class"],
    });
});

onBeforeUnmount(() => observer?.disconnect());

const shortDate = (date: string): string =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        timeZone: "UTC",
    });

const hasSpend = computed(() =>
    props.days.some((day) => Number(day.amount) > 0),
);

const total = computed(() =>
    props.days.reduce((sum, day) => sum + Number(day.amount), 0),
);

const peak = computed(() =>
    props.days.reduce((best, day) =>
        Number(day.amount) > Number(best.amount) ? day : best,
    ),
);

const summary = computed(() =>
    hasSpend.value
        ? `Daily spending for the last 30 days. Total ${formatCost(total.value.toFixed(12))}, highest ${formatCost(peak.value.amount)} on ${shortDate(peak.value.date)}.`
        : "No spending in the last 30 days.",
);

const reducedMotion =
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

const data = computed<ChartData<"bar">>(() => ({
    labels: props.days.map((day) => shortDate(day.date)),
    datasets: [
        {
            label: "Spent",
            // Numbers only for bar heights; every label shows the exact string.
            data: props.days.map((day) => Number(day.amount)),
            backgroundColor: colors.value.bar,
            hoverBackgroundColor: colors.value.bar,
            borderRadius: { topLeft: 4, topRight: 4 },
            borderSkipped: "bottom",
            categoryPercentage: 0.85,
            barPercentage: 1,
            maxBarThickness: 24,
        },
    ],
}));

const options = computed<ChartOptions<"bar">>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: reducedMotion ? false : { duration: 200 },
    interaction: { mode: "index", intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            displayColors: false,
            callbacks: {
                title: (items) =>
                    new Date(
                        `${props.days[items[0].dataIndex].date}T00:00:00Z`,
                    ).toLocaleDateString(undefined, {
                        weekday: "short",
                        month: "short",
                        day: "numeric",
                        timeZone: "UTC",
                    }),
                label: (item) => formatCost(props.days[item.dataIndex].amount),
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: {
                color: colors.value.text,
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 6,
            },
        },
        y: {
            beginAtZero: true,
            border: { display: false },
            grid: { color: colors.value.grid },
            ticks: {
                color: colors.value.text,
                maxTicksLimit: 4,
                callback: (value) => formatCost(String(value)),
            },
        },
    },
}));
</script>

<template>
    <section
        aria-labelledby="spend-heading"
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2 id="spend-heading" class="text-sm font-semibold">
            Spending, last 30 days
        </h2>

        <div v-if="hasSpend" class="mt-4 h-48" role="img" :aria-label="summary">
            <Bar v-if="colors.bar" :data="data" :options="options" />
        </div>
        <p v-else class="mt-4 text-sm text-muted-foreground">
            No spending yet. Your daily costs will appear here.
        </p>

        <table v-if="hasSpend" class="sr-only">
            <caption>
                Daily spending, last 30 days
            </caption>
            <thead>
                <tr>
                    <th scope="col">Day</th>
                    <th scope="col">Spent</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in days" :key="day.date">
                    <td>{{ shortDate(day.date) }}</td>
                    <td>{{ formatCost(day.amount) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
