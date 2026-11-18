export type GenerationStatus = "queued" | "processing" | "succeeded" | "failed";

export type GenerationRow = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    estimated_cost: string;
    actual_cost: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    created_at: string | null;
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
