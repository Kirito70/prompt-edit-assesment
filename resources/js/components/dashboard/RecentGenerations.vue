<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ImageOff } from "@lucide/vue";
import GenerationStatusBadge from "@/components/prompt-edit/GenerationStatusBadge.vue";
import { formatCost, isImageExpired } from "@/lib/generation";
import { history } from "@/routes";
import { show } from "@/routes/history";
import type { GenerationRow } from "@/types";

defineProps<{ rows: GenerationRow[] }>();
</script>

<template>
    <section
        aria-labelledby="recent-heading"
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <div class="flex items-center justify-between gap-4">
            <h2 id="recent-heading" class="text-sm font-semibold">
                Recent generations
            </h2>
            <Link
                :href="history()"
                class="inline-flex min-h-11 items-center rounded-md px-2 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                View all
            </Link>
        </div>

        <ul class="mt-2 divide-y">
            <li v-for="row in rows" :key="row.id">
                <Link
                    :href="show({ generation: row.id })"
                    class="flex items-center gap-3 rounded-md py-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <img
                        v-if="row.image_url && !isImageExpired(row)"
                        :src="row.image_url"
                        :alt="row.prompt"
                        loading="lazy"
                        width="48"
                        height="48"
                        class="size-12 shrink-0 rounded-md object-cover outline outline-1 -outline-offset-1 outline-black/10 dark:outline-white/10"
                    />
                    <div
                        v-else
                        class="flex size-12 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                    >
                        <ImageOff class="size-4" aria-hidden="true" />
                    </div>
                    <p class="line-clamp-2 min-w-0 flex-1 text-sm">
                        {{ row.prompt }}
                    </p>
                    <GenerationStatusBadge :status="row.status" />
                    <span class="w-20 shrink-0 text-right text-sm tabular-nums">
                        {{ formatCost(row.actual_cost ?? row.estimated_cost) }}
                    </span>
                </Link>
            </li>
        </ul>
    </section>
</template>
