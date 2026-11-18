<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from "@lucide/vue";
import type { Component } from "vue";
import { computed } from "vue";
import { Badge } from "@/components/ui/badge";
import type { GenerationStatus } from "@/types";

const props = defineProps<{ status: GenerationStatus }>();

type BadgeVariant = "default" | "secondary" | "destructive" | "outline";

const config: Record<
    GenerationStatus,
    { variant: BadgeVariant; label: string; icon: Component; spin?: boolean }
> = {
    queued: { variant: "outline", label: "Queued", icon: Clock },
    processing: {
        variant: "secondary",
        label: "Processing",
        icon: LoaderCircle,
        spin: true,
    },
    succeeded: { variant: "default", label: "Succeeded", icon: CircleCheck },
    failed: { variant: "destructive", label: "Failed", icon: CircleX },
};

const current = computed(() => config[props.status]);
</script>

<template>
    <!-- Icon plus text, so status is never conveyed by colour alone. -->
    <Badge :variant="current.variant" class="gap-1">
        <component
            :is="current.icon"
            class="size-3"
            :class="{ 'motion-safe:animate-spin': current.spin }"
            aria-hidden="true"
        />
        {{ current.label }}
    </Badge>
</template>
