import type { GenerationRow, GenerationStatus } from "@/types";

export const POLL_INTERVAL_MS = 4000;

export const isPending = (status: GenerationStatus): boolean =>
    status === "queued" || status === "processing";

export const isImageExpired = (
    row: Pick<GenerationRow, "image_url_expires_at">,
): boolean =>
    row.image_url_expires_at !== null &&
    new Date(row.image_url_expires_at).getTime() <= Date.now();

export const formatCost = (value: string | null): string =>
    value === null ? "—" : `$${Number(value).toFixed(4)}`;

export const formatDate = (value: string | null | undefined): string =>
    value ? new Date(value).toLocaleString() : "—";
