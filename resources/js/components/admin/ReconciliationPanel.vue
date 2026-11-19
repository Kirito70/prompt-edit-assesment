<script setup lang="ts">
import { CircleAlert, CircleCheck, TriangleAlert } from "@lucide/vue";
import { computed } from "vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { formatExact } from "@/lib/generation";
import type { Reconciliation } from "@/types";

const props = defineProps<{ data: Reconciliation }>();

// Differing days first: that is what the operator came to find.
const days = computed(() =>
    [...props.data.days].sort((a, b) =>
        a.status === b.status
            ? b.date.localeCompare(a.date)
            : a.status === "differs"
              ? -1
              : 1,
    ),
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <Alert v-if="!data.available" variant="destructive" role="alert">
            <TriangleAlert class="size-4" aria-hidden="true" />
            <AlertDescription>{{ data.error }}</AlertDescription>
        </Alert>

        <template v-else>
            <p
                class="flex items-center gap-2 text-sm font-medium"
                role="status"
            >
                <template v-if="data.differing === 0">
                    <CircleCheck class="size-4" aria-hidden="true" />
                    All {{ data.matched }} days match Pixelforge.
                </template>
                <template v-else>
                    <CircleAlert
                        class="size-4 text-destructive"
                        aria-hidden="true"
                    />
                    {{ data.differing }} of {{ data.matched + data.differing }}
                    days differ from Pixelforge.
                </template>
            </p>

            <p class="max-w-prose text-xs text-muted-foreground text-pretty">
                Compared with <code>GET /v1/usage</code> for the API key this
                app uses. Other keys report separately. A day matches only when
                the cost is identical and the generation counts agree. A
                difference usually means a webhook never arrived, or generations
                made with another key.
            </p>

            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <Table>
                    <caption class="sr-only">
                        Provider cost per day, ours versus Pixelforge
                    </caption>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Day (UTC)</TableHead>
                            <TableHead scope="col" class="text-right"
                                >Ours</TableHead
                            >
                            <TableHead scope="col" class="text-right"
                                >Pixelforge</TableHead
                            >
                            <TableHead scope="col" class="text-right"
                                >Difference</TableHead
                            >
                            <TableHead scope="col" class="text-right"
                                >Count (ours / theirs)</TableHead
                            >
                            <TableHead scope="col">Result</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="day in days" :key="day.date">
                            <TableCell class="font-medium tabular-nums">{{
                                day.date
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatExact(day.ours_cost)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatExact(day.theirs_cost)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatExact(day.difference)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">
                                {{ day.ours_count }} / {{ day.theirs_count }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        day.status === 'match'
                                            ? 'secondary'
                                            : 'destructive'
                                    "
                                    class="gap-1"
                                >
                                    <CircleCheck
                                        v-if="day.status === 'match'"
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    <CircleAlert
                                        v-else
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{
                                        day.status === "match"
                                            ? "Match"
                                            : "Differs"
                                    }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </template>
    </div>
</template>
