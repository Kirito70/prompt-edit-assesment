import type { GenerationRow, GenerationStatus } from "@/types";

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

/**
 * Every significant decimal (at least 4), for reconciliation where a tiny
 * difference matters. Display only: the exact string is what gets compared.
 */
export const formatExact = (value: string): string => {
    const negative = value.startsWith("-");
    const [whole, fraction = ""] = value.replace("-", "").split(".");
    const trimmed = fraction.replace(/0+$/, "").padEnd(4, "0");

    return `${negative ? "-" : ""}$${whole}.${trimmed}`;
};
