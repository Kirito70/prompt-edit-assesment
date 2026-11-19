<script setup lang="ts">
import { CircleCheck } from "@lucide/vue";
import { computed } from "vue";
import { Badge } from "@/components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import type { AdminAttention } from "@/types";

const props = defineProps<{ attention: AdminAttention }>();

const allClear = computed(
    () =>
        props.attention.stuck_total === 0 &&
        props.attention.events_unprocessed === 0 &&
        props.attention.events_with_errors === 0,
);

const duration = (minutes: number): string =>
    minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
</script>

<template>
    <div class="flex flex-col gap-6">
        <p
            v-if="allClear"
            class="flex items-center gap-2 text-sm font-medium"
            role="status"
        >
            <CircleCheck class="size-4" aria-hidden="true" />
            All clear: nothing is stuck and every webhook was applied.
        </p>

        <section aria-labelledby="stuck-heading" class="flex flex-col gap-2">
            <h3 id="stuck-heading" class="text-sm font-semibold">
                Unfinished generations
                <Badge
                    :variant="
                        attention.stuck_total > 0 ? 'destructive' : 'secondary'
                    "
                    class="ml-2"
                >
                    {{ attention.stuck_total }}
                </Badge>
            </h3>
            <p class="max-w-prose text-xs text-muted-foreground text-pretty">
                Queued or processing for more than 15 minutes. Pixelforge may
                have billed them, so they can explain a reconciliation
                difference.
            </p>

            <div
                v-if="attention.stuck.length > 0"
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <Table>
                    <caption class="sr-only">
                        Oldest unfinished generations
                    </caption>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Generation</TableHead>
                            <TableHead scope="col">User</TableHead>
                            <TableHead scope="col">Status</TableHead>
                            <TableHead scope="col">Submission</TableHead>
                            <TableHead scope="col" class="text-right"
                                >Waiting</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in attention.stuck" :key="row.id">
                            <TableCell class="font-mono text-xs">{{
                                row.id.slice(0, 8)
                            }}</TableCell>
                            <TableCell>{{ row.email }}</TableCell>
                            <TableCell>{{ row.status }}</TableCell>
                            <TableCell>{{ row.submission_status }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                duration(row.minutes)
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <p
                v-if="attention.stuck_total > attention.stuck.length"
                class="text-xs text-muted-foreground"
            >
                Showing the oldest {{ attention.stuck.length }} of
                {{ attention.stuck_total }}.
            </p>
        </section>

        <section aria-labelledby="events-heading" class="flex flex-col gap-2">
            <h3 id="events-heading" class="text-sm font-semibold">
                Webhook events
            </h3>
            <dl class="grid max-w-md grid-cols-2 gap-y-2 text-sm">
                <dt class="text-muted-foreground">
                    Not applied after 5 minutes
                </dt>
                <dd class="text-right font-medium tabular-nums">
                    {{ attention.events_unprocessed }}
                </dd>
                <dt class="text-muted-foreground">Failed with an error</dt>
                <dd class="text-right font-medium tabular-nums">
                    {{ attention.events_with_errors }}
                </dd>
            </dl>
            <p class="max-w-prose text-xs text-muted-foreground text-pretty">
                Events are retried every minute. Ones that stay here usually
                point to a generation we have no record of.
            </p>
        </section>
    </div>
</template>
