<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Spinner } from "@/components/ui/spinner";
import { useBuyCredits } from "@/composables/useBuyCredits";
import { store } from "@/routes/credits";

const { open } = useBuyCredits();
const page = usePage();

const packages = computed(() => page.props.credits?.packages ?? []);
const chosen = ref<string | null>(null);
const processing = ref(false);

const selected = computed(
    () =>
        chosen.value ?? packages.value[1]?.id ?? packages.value[0]?.id ?? null,
);

const money = (amount: string): string => `$${Number(amount).toFixed(2)}`;

const buy = (): void => {
    if (selected.value === null || processing.value) {
        return;
    }

    router.post(
        store.url(),
        { package: selected.value },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription class="text-pretty">
                    This is a demo: no payment is taken. Credits are added to
                    your account straight away.
                </DialogDescription>
            </DialogHeader>

            <div
                role="radiogroup"
                aria-label="Credit package"
                class="grid gap-2 sm:grid-cols-3"
            >
                <button
                    v-for="pack in packages"
                    :key="pack.id"
                    type="button"
                    role="radio"
                    :aria-checked="selected === pack.id"
                    class="min-h-11 rounded-lg border px-3 py-3 text-center text-lg font-semibold tabular-nums transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        selected === pack.id
                            ? 'border-primary bg-primary/10'
                            : 'border-border hover:bg-accent'
                    "
                    @click="chosen = pack.id"
                >
                    {{ money(pack.amount) }}
                </button>
            </div>

            <DialogFooter>
                <Button
                    class="min-h-11"
                    :disabled="processing || selected === null"
                    @click="buy"
                >
                    <Spinner v-if="processing" class="size-4" />
                    Add credits
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
