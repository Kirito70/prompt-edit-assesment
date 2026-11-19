import { ref } from "vue";

// One dialog, mounted once in the sidebar, opened from anywhere.
const open = ref(false);

export function useBuyCredits() {
    return {
        open,
        openBuyCredits: (): void => {
            open.value = true;
        },
    };
}
