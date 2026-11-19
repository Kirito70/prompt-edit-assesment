<script setup lang="ts">
import type { Component } from "vue";

defineProps<{
    label: string;
    value: string | number;
    icon: Component;
    hint?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <div class="flex items-center gap-2 text-sm text-muted-foreground">
            <component :is="icon" class="size-4" aria-hidden="true" />
            <span>{{ label }}</span>
        </div>
        <p class="text-2xl font-semibold tabular-nums">{{ value }}</p>
        <p v-if="hint" class="text-xs text-muted-foreground text-pretty">
            {{ hint }}
        </p>
    </div>
</template>
