<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import Heading from "@/components/Heading.vue";
import GenerationStatusBadge from "@/components/prompt-edit/GenerationStatusBadge.vue";
import { EyeIcon } from "@lucide/vue";
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
import { formatCost, formatDate, isImageExpired } from "@/lib/generation";
import type { GenerationRow, Paginated } from "@/types";
import { show } from "@/routes/history";

defineProps<{
    generations: Paginated<GenerationRow>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "History", href: history() }],
    },
});

// Live: the server pushes a refresh over WebSockets whenever one of these
// generations changes (see GenerationController::index), so there is no polling.

const paginationLabel = (label: string): string =>
    label.replace("&laquo; ", "").replace(" &raquo;", "");
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
                        <TableHead class="w-12"
                            ><span class="sr-only">View</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty
                        v-if="generations.data.length === 0"
                        :colspan="7"
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
                            <GenerationStatusBadge :status="row.status" />
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

                        <TableCell class="w-12">
                            <Link
                                :href="show({ generation: row.id })"
                                component="prompt-edit/generations/show"
                                :page-props="{ generation: row }"
                                prefetch
                                class="inline-flex size-11 items-center justify-center rounded-md hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="`View generation: ${row.prompt}`"
                            >
                                <EyeIcon class="size-4" aria-hidden="true" />
                            </Link>
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
