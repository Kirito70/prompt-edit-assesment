<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ArrowDownRight, ArrowUpRight, Wallet } from "@lucide/vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import Heading from "@/components/Heading.vue";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { useBuyCredits } from "@/composables/useBuyCredits";
import { formatCost, formatDate } from "@/lib/generation";
import { index } from "@/routes/credits";
import { show } from "@/routes/history";
import type { CreditTransactionRow, Paginated } from "@/types";

defineProps<{
    balance: string;
    transactions: Paginated<CreditTransactionRow>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Credits", href: index() }],
    },
});

const { openBuyCredits } = useBuyCredits();

const paginationLabel = (label: string): string =>
    label.replace("&laquo; ", "").replace(" &raquo;", "");

const isCredit = (amount: string): boolean => !amount.startsWith("-");

// A charge reads "−$0.0786", a purchase "+$10.0000": the sign is text, never colour alone.
const signed = (amount: string): string =>
    `${isCredit(amount) ? "+" : "−"}${formatCost(amount.replace("-", ""))}`;
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Credits"
                description="Everything you bought and spent, newest first"
            />
            <Button class="min-h-11" @click="openBuyCredits">
                <Wallet class="size-4" aria-hidden="true" />
                Buy credits
            </Button>
        </div>

        <p class="text-sm">
            Balance
            <strong class="ml-1 text-lg tabular-nums">{{
                formatCost(balance)
            }}</strong>
        </p>

        <p
            v-if="transactions.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            Nothing yet. Buy credits to start generating.
        </p>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <Table>
                <caption class="sr-only">
                    Credit purchases and charges
                </caption>
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col">When</TableHead>
                        <TableHead scope="col">What</TableHead>
                        <TableHead scope="col" class="text-right">
                            Amount
                        </TableHead>
                        <TableHead scope="col" class="text-right">
                            Balance after
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in transactions.data" :key="row.id">
                        <TableCell class="whitespace-nowrap">
                            {{ formatDate(row.created_at) }}
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    row.type === 'purchase'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                class="gap-1"
                            >
                                <ArrowUpRight
                                    v-if="row.type === 'purchase'"
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                <ArrowDownRight
                                    v-else
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{
                                    row.type === "purchase"
                                        ? "Purchase"
                                        : "Image generated"
                                }}
                            </Badge>
                            <Link
                                v-if="row.generation_id"
                                :href="show(row.generation_id)"
                                class="ml-2 inline-flex min-h-11 items-center text-sm underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                View image
                            </Link>
                        </TableCell>
                        <TableCell class="text-right font-medium tabular-nums">
                            {{ signed(row.amount) }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ formatCost(row.balance_after) }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <nav
            v-if="transactions.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Pagination"
        >
            <template v-for="link in transactions.links" :key="link.label">
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
