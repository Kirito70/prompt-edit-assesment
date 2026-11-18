export type GenerationStatus = "queued" | "processing" | "succeeded" | "failed";

export type GenerationRow = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    /** Null when the user generated without a price being shown. */
    estimated_cost: string | null;
    actual_cost: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    created_at: string | null;
};

/**
 * The detail page record. The extra fields are optional because an instant
 * visit from the history list renders the page with only the row's fields
 * until the full record loads.
 */
export type Generation = GenerationRow & {
    reference_images?: string[];
    submission_status?: string;
    error_code?: string | null;
    provider_completed_at?: string | null;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

export type EstimateInput = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

export type EstimateResponse = {
    customer_charge: string;
    range: { low: string; high: string };
    tokens: {
        input_text_tokens: number;
        input_image_tokens: number;
        output_tokens: number;
        reasoning_tokens: number;
    };
};

export type GenerationLimits = {
    prompt: number;
    reference_images: number;
};
