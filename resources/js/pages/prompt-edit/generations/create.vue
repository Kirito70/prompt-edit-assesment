<script setup lang="ts">
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { Plus } from "@lucide/vue";
import { computed, ref, watch } from "vue";
import Heading from "@/components/Heading.vue";
import InputError from "@/components/InputError.vue";
import EstimatePanel from "@/components/prompt-edit/EstimatePanel.vue";
import ReferenceImageRow from "@/components/prompt-edit/ReferenceImageRow.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Textarea } from "@/components/ui/textarea";
import { useBuyCredits } from "@/composables/useBuyCredits";
import { useGenerationEstimate } from "@/composables/useGenerationEstimate";
import { newClientRequestId } from "@/lib/clientRequestId";
import { formatCost } from "@/lib/generation";
import { generate } from "@/routes";
import { store } from "@/routes/generate";
import type { GenerationLimits } from "@/types";

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    limits: GenerationLimits;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Generate", href: generate() }],
    },
});

const SIZE_LABELS: Record<string, string> = {
    "1024x1024": "1024 × 1024 · Square",
    "1536x1024": "1536 × 1024 · Landscape",
    "1024x1536": "1024 × 1536 · Portrait",
};

const QUALITY_LABELS: Record<string, string> = {
    low: "Low",
    medium: "Medium",
    high: "High",
};

// The id is made once, so a double click or retry is one generation.
const form = useForm({
    client_request_id: newClientRequestId(),
    prompt: "",
    size: "1024x1024",
    quality: "medium",
    reference_images: [] as string[],
    // Only set once the user confirms generating with no price shown.
    confirmed_without_estimate: false,
});

const { estimate, status, message, retry } = useGenerationEstimate(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: [...form.reference_images],
}));

const canAddImage = computed(
    () => form.reference_images.length < props.limits.reference_images,
);
// A price that failed to load must not block generating, but the user has to
// confirm first (a wrong or invalid form still cannot be submitted).
const priceUnavailable = computed(() => status.value === "error");
const confirmOpen = ref(false);

// The server decides; this only warns early. The price could be as high as the
// top of the range, and with no price the user needs some credit to continue.
const page = usePage();
const credits = computed(() => page.props.credits);
const { openBuyCredits } = useBuyCredits();

const shortOnCredits = computed(() => {
    const available = Number(credits.value?.available ?? 0);

    if (estimate.value !== null) {
        return available < Number(estimate.value.range.high);
    }

    return priceUnavailable.value && available <= 0;
});

const canSubmit = computed(
    () =>
        !form.processing &&
        !shortOnCredits.value &&
        ((status.value === "ready" && estimate.value !== null) ||
            priceUnavailable.value),
);

// A real price makes the confirmation moot, so never carry it over.
watch(status, (value) => {
    if (value === "ready") {
        form.confirmed_without_estimate = false;
    }
});

const addImage = (): void => {
    if (canAddImage.value) {
        form.reference_images.push("");
    }
};

const removeImage = (index: number): void => {
    form.reference_images.splice(index, 1);
};

// The server can also report errors that are not form fields (such as the price
// being unavailable), and Inertia types `errors` by field name only.
const serverError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const imageError = (index: number): string | undefined =>
    serverError(`reference_images.${index}`);

const submit = (): void => {
    if (!canSubmit.value) {
        return;
    }

    if (priceUnavailable.value) {
        confirmOpen.value = true;

        return;
    }

    form.submit(store());
};

const confirmAndSubmit = (): void => {
    form.confirmed_without_estimate = true;
    form.submit(store());
};
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Generate an image"
            description="Describe what you want. You will see the price before you submit."
        />

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <form
                class="flex max-w-2xl flex-col gap-6"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="prompt">Prompt</Label>
                    <Textarea
                        id="prompt"
                        v-model="form.prompt"
                        name="prompt"
                        rows="5"
                        :maxlength="limits.prompt"
                        placeholder="A watercolor fox sleeping under a lantern…"
                        aria-describedby="prompt-count"
                        :aria-invalid="form.errors.prompt ? true : undefined"
                    />
                    <p
                        id="prompt-count"
                        class="text-right text-xs text-muted-foreground tabular-nums"
                    >
                        {{ form.prompt.length.toLocaleString() }} /
                        {{ limits.prompt.toLocaleString() }}
                    </p>
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <Select v-model="form.size">
                            <SelectTrigger id="size" class="min-h-11 w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="size in sizes"
                                    :key="size"
                                    :value="size"
                                >
                                    {{ SIZE_LABELS[size] ?? size }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.size" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <Select v-model="form.quality">
                            <SelectTrigger id="quality" class="min-h-11 w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="quality in qualities"
                                    :key="quality"
                                    :value="quality"
                                >
                                    {{ QUALITY_LABELS[quality] ?? quality }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground text-pretty">
                            Higher quality costs more.
                        </p>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <fieldset class="grid gap-3">
                    <legend class="mb-1 text-sm font-medium">
                        Reference images
                        <span class="font-normal text-muted-foreground">
                            (optional, up to {{ limits.reference_images }})
                        </span>
                    </legend>

                    <ReferenceImageRow
                        v-for="(_, index) in form.reference_images"
                        :key="index"
                        v-model="form.reference_images[index]"
                        :position="index + 1"
                        :error="imageError(index)"
                        @remove="removeImage(index)"
                    />

                    <InputError :message="form.errors.reference_images" />

                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            :disabled="!canAddImage"
                            @click="addImage"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Add image URL
                        </Button>
                        <p
                            class="mt-2 text-xs text-muted-foreground text-pretty"
                        >
                            Each reference image adds to the price.
                        </p>
                    </div>
                </fieldset>

                <!-- Small screens: the price sits right above the button. -->
                <EstimatePanel
                    class="lg:hidden"
                    :status="status"
                    :estimate="estimate"
                    :message="message"
                    @retry="retry"
                />

                <Alert
                    v-if="shortOnCredits || serverError('credits')"
                    variant="destructive"
                    role="alert"
                >
                    <AlertDescription class="flex flex-wrap items-center gap-3">
                        <span class="text-pretty">
                            You have {{ formatCost(credits?.available ?? "0") }}
                            in credits, which does not cover this image.
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            @click="openBuyCredits"
                        >
                            Buy credits
                        </Button>
                    </AlertDescription>
                </Alert>

                <Alert
                    v-if="serverError('estimate')"
                    variant="destructive"
                    role="alert"
                >
                    <AlertDescription>{{
                        serverError("estimate")
                    }}</AlertDescription>
                </Alert>

                <div>
                    <Button
                        type="submit"
                        size="lg"
                        class="min-h-11 w-full sm:w-auto"
                        :disabled="!canSubmit"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ form.processing ? "Generating…" : "Generate image" }}
                    </Button>
                </div>
            </form>

            <AlertDialog v-model:open="confirmOpen">
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Generate without a price?
                        </AlertDialogTitle>
                        <AlertDialogDescription class="text-pretty">
                            We couldn’t work out the price right now, so we
                            can’t show you an estimate. If you continue, you’ll
                            be charged the actual cost once your image is ready.
                            Failed generations cost nothing.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel class="min-h-11">
                            Go back
                        </AlertDialogCancel>
                        <AlertDialogAction
                            class="min-h-11"
                            @click="confirmAndSubmit"
                        >
                            Generate anyway
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <aside class="hidden lg:block">
                <EstimatePanel
                    class="sticky top-4"
                    :status="status"
                    :estimate="estimate"
                    :message="message"
                    @retry="retry"
                />
            </aside>
        </div>
    </div>
</template>
