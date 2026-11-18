<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, watch } from "vue";
import Heading from "@/components/Heading.vue";
import { Badge } from "@/components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { history } from "@/routes";
import type { GenerationRow, GenerationStatus, Paginated } from "@/types";

const POLL_INTERVAL_MS = 4000;

const props = defineProps<{
    generations: Paginated<GenerationRow>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "History", href: history() }],
    },
});

const statusVariant: Record<
    GenerationStatus,
    "default" | "secondary" | "destructive" | "outline"
> = {
    queued: "outline",
    processing: "secondary",
    succeeded: "default",
    failed: "destructive",
};

const hasPending = computed(() =>
    props.generations.data.some(
        (row) => row.status === "queued" || row.status === "processing",
    ),
);

// Live status: refresh only the generations prop while something is in flight.
let pollTimer: ReturnType<typeof setInterval> | null = null;

const stopPolling = (): void => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

watch(
    hasPending,
    (pending) => {
        if (!pending) {
            stopPolling();

            return;
        }

        pollTimer ??= setInterval(
            () => router.reload({ only: ["generations"] }),
            POLL_INTERVAL_MS,
        );
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);

const paginationLabel = (label: string): string =>
    label.replace("&laquo; ", "").replace(" &raquo;", "");

const isImageExpired = (row: GenerationRow): boolean =>
    row.image_url_expires_at !== null &&
    new Date(row.image_url_expires_at).getTime() <= Date.now();

const formatCost = (value: string | null): string =>
    value === null ? "—" : `$${Number(value).toFixed(4)}`;

const formatDate = (value: string | null): string =>
    value === null ? "" : new Date(value).toLocaleString();
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="History"
            description="Your image generations, with live status and cost"
        />

        <div
            class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Image</TableHead>
                        <TableHead>Prompt</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="text-right">Estimated</TableHead>
                        <TableHead class="text-right">Actual</TableHead>
                        <TableHead>Created</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty
                        v-if="generations.data.length === 0"
                        :colspan="6"
                    >
                        No generations yet.
                    </TableEmpty>
                    <TableRow v-for="row in generations.data" :key="row.id">
                        <TableCell class="w-24">
                            <img
                                v-if="row.image_url && !isImageExpired(row)"
                                :src="row.image_url"
                                :alt="row.prompt"
                                loading="lazy"
                                class="size-16 rounded-md object-cover"
                            />
                            <div
                                v-else
                                class="flex size-16 items-center justify-center rounded-md bg-muted text-center text-xs text-muted-foreground"
                            >
                                {{ row.image_url ? "Expired" : "No image" }}
                            </div>
                        </TableCell>
                        <TableCell class="max-w-md">
                            <p class="line-clamp-2">{{ row.prompt }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.size }} · {{ row.quality }}
                            </p>
                            <p
                                v-if="
                                    row.status === 'failed' && row.error_message
                                "
                                class="text-xs text-destructive"
                            >
                                {{ row.error_message }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant[row.status]">
                                {{ row.status }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ formatCost(row.estimated_cost) }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ formatCost(row.actual_cost) }}
                        </TableCell>
                        <TableCell
                            class="whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatDate(row.created_at) }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <nav
            v-if="generations.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Pagination"
        >
            <template v-for="link in generations.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        link.active ? 'bg-primary text-primary-foreground' : ''
                    "
                >
                    {{ paginationLabel(link.label) }}
                </Link>
                <span
                    v-else
                    class="rounded-md border px-3 py-1 text-sm text-muted-foreground"
                >
                    {{ paginationLabel(link.label) }}
                </span>
            </template>
        </nav>
    </div>
</template>
