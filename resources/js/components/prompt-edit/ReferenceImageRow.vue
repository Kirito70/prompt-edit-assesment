<script setup lang="ts">
import { X } from "@lucide/vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

defineProps<{
    /** 1-based position, used in labels. */
    position: number;
    error?: string;
}>();

defineEmits<{ remove: [] }>();

const url = defineModel<string>({ required: true });
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center gap-2">
            <Label :for="`reference-${position}`" class="sr-only">
                Reference image {{ position }} URL
            </Label>
            <Input
                :id="`reference-${position}`"
                v-model="url"
                type="url"
                inputmode="url"
                class="min-h-11"
                :placeholder="`https://example.com/image-${position}.png`"
                :aria-invalid="error ? true : undefined"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-11 shrink-0"
                :aria-label="`Remove reference image ${position}`"
                @click="$emit('remove')"
            >
                <X class="size-4" aria-hidden="true" />
            </Button>
        </div>
        <InputError :message="error" />
    </div>
</template>
