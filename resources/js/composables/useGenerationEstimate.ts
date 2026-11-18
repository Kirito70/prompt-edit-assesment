import { useHttp } from "@inertiajs/vue3";
import { useDebounceFn } from "@vueuse/core";
import { onBeforeUnmount, ref, watch } from "vue";
import { estimate as estimateRoute } from "@/routes/generate";
import type { EstimateInput, EstimateResponse } from "@/types";

const DEBOUNCE_MS = 400;
const UNAVAILABLE_MESSAGE =
    "Pricing is temporarily unavailable. Try again in a moment.";
const BAD_URL_MESSAGE =
    "Enter full image URLs starting with http:// or https:// to see the price.";

export type EstimateStatus = "idle" | "loading" | "ready" | "invalid" | "error";

const isHttpUrl = (value: string): boolean => {
    try {
        const url = new URL(value);

        return url.protocol === "http:" || url.protocol === "https:";
    } catch {
        return false;
    }
};

/**
 * Keeps a server-priced estimate in step with the form. The price is always
 * worked out on the server (exact decimals); this only asks for it, debounced,
 * and ignores answers that a newer request has already replaced.
 */
export function useGenerationEstimate(source: () => EstimateInput) {
    const estimate = ref<EstimateResponse | null>(null);
    const status = ref<EstimateStatus>("idle");
    const message = ref<string | null>(null);

    const http = useHttp<EstimateInput, EstimateResponse>({
        prompt: "",
        size: "1024x1024",
        quality: "medium",
        reference_images: [],
    });

    let latest = 0;

    const run = async (): Promise<void> => {
        const input = source();
        const requestId = ++latest;

        http.cancel();

        if (input.prompt.trim() === "") {
            estimate.value = null;
            message.value = null;
            status.value = "idle";

            return;
        }

        const images = input.reference_images.filter(
            (url) => url.trim() !== "",
        );

        if (!images.every(isHttpUrl)) {
            estimate.value = null;
            message.value = BAD_URL_MESSAGE;
            status.value = "invalid";

            return;
        }

        status.value = "loading";
        http.transform(() => ({ ...input, reference_images: images }));

        try {
            const result = await http.post(estimateRoute.url());

            if (requestId !== latest) {
                return;
            }

            estimate.value = result;
            message.value = null;
            status.value = "ready";
        } catch {
            if (requestId !== latest) {
                return;
            }

            estimate.value = null;
            status.value = http.hasErrors ? "invalid" : "error";
            message.value = http.hasErrors
                ? (Object.values(http.errors)[0] ?? BAD_URL_MESSAGE)
                : UNAVAILABLE_MESSAGE;
        }
    };

    const debouncedRun = useDebounceFn(run, DEBOUNCE_MS);

    watch(
        () => JSON.stringify(source()),
        () => {
            // The old price no longer matches the form, so it cannot be submitted.
            estimate.value = null;
            status.value = source().prompt.trim() === "" ? "idle" : "loading";
            void debouncedRun();
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        latest++;
        http.cancel();
    });

    return { estimate, status, message, retry: run };
}
