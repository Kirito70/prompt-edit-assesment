<script setup lang="ts">
import { RefreshCw, TriangleAlert } from "@lucide/vue";
import { computed } from "vue";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { EstimateStatus } from "@/composables/useGenerationEstimate";
import { formatCost } from "@/lib/generation";
import type { EstimateResponse } from "@/types";

const props = defineProps<{
    status: EstimateStatus;
    estimate: EstimateResponse | null;
    message: string | null;
}>();

defineEmits<{ retry: [] }>();

const lines = computed(() => {
    const tokens = props.estimate?.tokens;

    if (!tokens) {
        return [];
    }

    return [
        { label: "Prompt text", value: tokens.input_text_tokens },
        { label: "Reference images", value: tokens.input_image_tokens },
        { label: "Image output", value: tokens.output_tokens },
        { label: "Reasoning (varies)", value: tokens.reasoning_tokens },
    ];
});
</script>

<template>
    <section
        aria-labelledby="estimate-heading"
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2 id="estimate-heading" class="text-sm font-semibold">
            Estimated cost
        </h2>

        <!-- Announced politely as the price changes, without stealing focus. -->
        <div aria-live="polite" class="mt-3 min-h-16">
            <template v-if="status === 'idle'">
                <p class="text-sm text-muted-foreground">
                    Write a prompt to see what it will cost.
                </p>
            </template>

            <template v-else-if="status === 'loading'">
                <Skeleton class="h-8 w-32" />
                <Skeleton class="mt-2 h-4 w-44" />
                <span class="sr-only">Working out the price…</span>
            </template>

            <template v-else-if="status === 'ready' && estimate">
                <p class="text-3xl font-semibold tabular-nums">
                    ≈ {{ formatCost(estimate.customer_charge) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground tabular-nums">
                    Likely {{ formatCost(estimate.range.low) }}–{{
                        formatCost(estimate.range.high)
                    }}
                </p>
            </template>

            <template v-else>
                <div class="flex items-start gap-2 text-sm" role="alert">
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <p class="text-pretty">{{ message }}</p>
                </div>
                <p
                    v-if="status === 'error'"
                    class="mt-2 text-sm text-muted-foreground text-pretty"
                >
                    You can still generate an image. We’ll ask you to confirm
                    first.
                </p>
                <Button
                    v-if="status === 'error'"
                    type="button"
                    variant="outline"
                    class="mt-3 min-h-11"
                    @click="$emit('retry')"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Try again
                </Button>
            </template>
        </div>

        <dl
            v-if="lines.length > 0"
            class="mt-4 grid grid-cols-2 gap-y-1 border-t pt-3 text-sm"
        >
            <template v-for="line in lines" :key="line.label">
                <dt class="text-muted-foreground">{{ line.label }}</dt>
                <dd class="text-right tabular-nums">
                    {{ line.value.toLocaleString() }}
                </dd>
            </template>
        </dl>

        <p class="mt-4 text-xs text-muted-foreground text-pretty">
            The final price is set when your image is ready and can differ
            slightly. Failed generations cost nothing.
        </p>
    </section>
</template>
