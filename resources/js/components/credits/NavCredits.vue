<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { Wallet } from "@lucide/vue";
import { computed } from "vue";
import BuyCreditsDialog from "@/components/credits/BuyCreditsDialog.vue";
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { useBuyCredits } from "@/composables/useBuyCredits";
import { formatCost } from "@/lib/generation";

const page = usePage();
const credits = computed(() => page.props.credits);
const { openBuyCredits } = useBuyCredits();
</script>

<template>
    <SidebarGroup v-if="credits" class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton
                    tooltip="Buy credits"
                    class="h-auto min-h-11 py-2"
                    @click="openBuyCredits"
                >
                    <Wallet aria-hidden="true" />
                    <span class="flex flex-col items-start leading-tight">
                        <span class="text-xs text-muted-foreground"
                            >Available credits</span
                        >
                        <span
                            class="font-semibold tabular-nums"
                            aria-live="polite"
                        >
                            {{ formatCost(credits.available) }}
                        </span>
                    </span>
                    <span class="ml-auto text-xs font-medium underline-offset-2"
                        >Buy</span
                    >
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
    <BuyCreditsDialog />
</template>
