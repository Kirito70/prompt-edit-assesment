<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref, watch } from "vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { dashboard } from "@/routes/admin";

const props = defineProps<{
    start: string;
    end: string;
    userId: number | null;
}>();

const MAX_DAYS = 92;

const start = ref(props.start);
const end = ref(props.end);

watch(
    () => [props.start, props.end],
    ([newStart, newEnd]) => {
        start.value = newStart;
        end.value = newEnd;
    },
);

const todayUtc = (): string => new Date().toISOString().slice(0, 10);

const daysAgo = (days: number): string => {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() - days);

    return date.toISOString().slice(0, 10);
};

const apply = (from: string, to: string): void => {
    router.get(
        dashboard.url(),
        { start: from, end: to, user: props.userId ?? undefined },
        { preserveScroll: true, preserveState: true },
    );
};

const quickRanges = [
    { label: "Last 7 days", days: 7 },
    { label: "Last 30 days", days: 30 },
    { label: "Last 90 days", days: MAX_DAYS - 2 },
];
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="apply(start, end)"
    >
        <div class="grid gap-1.5">
            <Label for="range-start">From (UTC)</Label>
            <Input
                id="range-start"
                v-model="start"
                type="date"
                class="min-h-11 w-40"
                :max="end"
                required
            />
        </div>
        <div class="grid gap-1.5">
            <Label for="range-end">To (UTC)</Label>
            <Input
                id="range-end"
                v-model="end"
                type="date"
                class="min-h-11 w-40"
                :min="start"
                :max="todayUtc()"
                required
            />
        </div>
        <Button type="submit" class="min-h-11">Apply</Button>

        <div
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="Quick ranges"
        >
            <Button
                v-for="range in quickRanges"
                :key="range.days"
                type="button"
                variant="outline"
                class="min-h-11"
                @click="apply(daysAgo(range.days - 1), todayUtc())"
            >
                {{ range.label }}
            </Button>
        </div>
    </form>
</template>
