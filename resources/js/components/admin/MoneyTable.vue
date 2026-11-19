<script
    setup
    lang="ts"
    generic="T extends AdminSummary & { key: string | number }"
>
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { formatCost } from "@/lib/generation";
import type { AdminSummary } from "@/types";

defineProps<{
    caption: string;
    firstColumn: string;
    rows: T[];
}>();

// Typed per row so callers read `row.date` or `row.email` without casting.
defineSlots<{
    label(props: { row: T }): unknown;
    action?(props: { row: T }): unknown;
}>();
</script>

<template>
    <div
        class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
    >
        <Table>
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <TableHeader>
                <TableRow>
                    <TableHead scope="col">{{ firstColumn }}</TableHead>
                    <TableHead scope="col" class="text-right"
                        >Generations</TableHead
                    >
                    <TableHead scope="col" class="text-right"
                        >Credits charged</TableHead
                    >
                    <TableHead scope="col" class="text-right"
                        >Provider cost</TableHead
                    >
                    <TableHead scope="col" class="text-right">Profit</TableHead>
                    <TableHead scope="col" class="w-12">
                        <span class="sr-only">Actions</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="row in rows" :key="row.key">
                    <TableCell class="font-medium">
                        <slot name="label" :row="row" />
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ row.generations.toLocaleString() }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ formatCost(row.credits) }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ formatCost(row.provider_cost) }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ formatCost(row.profit) }}
                    </TableCell>
                    <TableCell>
                        <slot name="action" :row="row" />
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
