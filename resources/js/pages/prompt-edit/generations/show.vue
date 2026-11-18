<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ArrowLeft, ExternalLink, ImageOff } from "@lucide/vue";
import { computed } from "vue";
import Heading from "@/components/Heading.vue";
import GenerationStatusBadge from "@/components/prompt-edit/GenerationStatusBadge.vue";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
    formatCost,
    formatDate,
    isImageExpired,
    isPending,
} from "@/lib/generation";
import { history } from "@/routes";
import type { Generation } from "@/types";

const props = defineProps<{
    generation: Generation;
}>();

const shortId = (id: string): string => id.slice(0, 8);

defineOptions({
    layout: (pageProps: { generation: Generation }) => ({
        breadcrumbs: [
            { title: "History", href: history() },
            { title: `Generation ${pageProps.generation.id.slice(0, 8)}` },
        ],
    }),
});

const pending = computed(() => isPending(props.generation.status));
const expired = computed(() => isImageExpired(props.generation));
const hasImage = computed(
    () => props.generation.image_url !== null && !expired.value,
);
const referenceImages = computed(() => props.generation.reference_images ?? []);

// Live: the server pushes a refresh over WebSockets whenever this generation
// changes (see GenerationController::show), so there is no polling.
</script>

<template>
    <Head :title="`Generation ${shortId(generation.id)}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="`Generation ${shortId(generation.id)}`"
                description="Result, cost and details for this image"
            />
            <div class="flex items-center gap-3">
                <!-- Announced politely when the status changes, without stealing focus. -->
                <div aria-live="polite">
                    <GenerationStatusBadge :status="generation.status" />
                </div>
                <Button as-child variant="outline" class="min-h-11">
                    <Link :href="history()" preserve-scroll>
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        History
                    </Link>
                </Button>
            </div>
        </div>

        <Alert
            v-if="generation.status === 'failed'"
            variant="destructive"
            role="alert"
        >
            <AlertTitle>Generation failed</AlertTitle>
            <AlertDescription>
                <p class="text-pretty">
                    {{
                        generation.error_message ??
                        "The provider could not complete this image."
                    }}
                </p>
                <p v-if="generation.error_code" class="font-mono text-xs">
                    {{ generation.error_code }}
                </p>
                <p>You were not charged for this attempt.</p>
            </AlertDescription>
        </Alert>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <!-- Image: the hero of the page. -->
            <section aria-label="Generated image">
                <div
                    class="max-w-2xl rounded-xl border border-sidebar-border/70 p-2 dark:border-sidebar-border"
                >
                    <img
                        v-if="hasImage"
                        :src="generation.image_url!"
                        :alt="generation.prompt"
                        width="1024"
                        height="1024"
                        class="aspect-square w-full rounded-sm object-cover outline outline-1 -outline-offset-1 outline-black/10 dark:outline-white/10"
                    />
                    <div
                        v-else-if="pending"
                        class="relative aspect-square w-full"
                        role="status"
                    >
                        <Skeleton class="size-full rounded-sm" />
                        <p
                            class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground"
                        >
                            Generating…
                        </p>
                    </div>
                    <div
                        v-else
                        class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-sm bg-muted text-center text-sm text-muted-foreground"
                    >
                        <ImageOff class="size-6" aria-hidden="true" />
                        <p v-if="expired">
                            Image expired on
                            {{ formatDate(generation.image_url_expires_at) }}
                        </p>
                        <p v-else>No image</p>
                    </div>
                </div>

                <Button v-if="hasImage" as-child class="mt-4 min-h-11">
                    <a
                        :href="generation.image_url!"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink class="size-4" aria-hidden="true" />
                        Open full size
                    </a>
                </Button>
            </section>

            <!-- Cost and details. -->
            <div class="flex flex-col gap-6">
                <section aria-labelledby="cost-heading">
                    <h2 id="cost-heading" class="mb-2 text-sm font-semibold">
                        Cost
                    </h2>
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-muted-foreground">Estimated</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatCost(generation.estimated_cost) }}
                        </dd>
                        <dt class="text-muted-foreground">Actual</dt>
                        <dd class="text-right font-medium tabular-nums">
                            {{ formatCost(generation.actual_cost) }}
                        </dd>
                    </dl>
                </section>

                <section aria-labelledby="details-heading">
                    <h2 id="details-heading" class="mb-2 text-sm font-semibold">
                        Details
                    </h2>
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-muted-foreground">Size</dt>
                        <dd class="text-right">{{ generation.size }}</dd>
                        <dt class="text-muted-foreground">Quality</dt>
                        <dd class="text-right">{{ generation.quality }}</dd>
                        <dt class="text-muted-foreground">Created</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatDate(generation.created_at) }}
                        </dd>
                        <dt class="text-muted-foreground">Completed</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatDate(generation.provider_completed_at) }}
                        </dd>
                        <template v-if="hasImage">
                            <dt class="text-muted-foreground">Image expires</dt>
                            <dd class="text-right tabular-nums">
                                {{
                                    formatDate(generation.image_url_expires_at)
                                }}
                            </dd>
                        </template>
                    </dl>
                </section>
            </div>
        </div>

        <section aria-labelledby="prompt-heading">
            <h2 id="prompt-heading" class="mb-2 text-sm font-semibold">
                Prompt
            </h2>
            <p class="max-w-[65ch] leading-[1.4] whitespace-pre-wrap">
                {{ generation.prompt }}
            </p>
        </section>

        <section
            v-if="referenceImages.length > 0"
            aria-labelledby="refs-heading"
        >
            <h2 id="refs-heading" class="mb-2 text-sm font-semibold">
                Reference images
            </h2>
            <ul class="flex flex-wrap gap-3">
                <li v-for="(url, index) in referenceImages" :key="url">
                    <a
                        :href="url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <img
                            :src="url"
                            :alt="`Reference image ${index + 1} of ${referenceImages.length}`"
                            loading="lazy"
                            width="96"
                            height="96"
                            class="size-24 rounded-md object-cover outline outline-1 -outline-offset-1 outline-black/10 dark:outline-white/10"
                        />
                    </a>
                </li>
            </ul>
        </section>
    </div>
</template>
