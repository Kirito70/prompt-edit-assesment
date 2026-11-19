export type AdminFilters = {
    start: string;
    end: string;
    user: { id: number; name: string; email: string } | null;
};

export type AdminSummary = {
    generations: number;
    credits: string;
    provider_cost: string;
    profit: string;
};

export type AdminDailyRow = AdminSummary & {
    date: string;
};

export type AdminUserRow = AdminSummary & {
    id: number;
    name: string;
    email: string;
};

export type AdminStuckGeneration = {
    id: string;
    email: string;
    status: string;
    submission_status: string;
    minutes: number;
};

export type AdminAttention = {
    stuck_total: number;
    stuck: AdminStuckGeneration[];
    events_unprocessed: number;
    events_with_errors: number;
};

export type ReconciliationDay = {
    date: string;
    ours_cost: string;
    theirs_cost: string;
    difference: string;
    ours_count: number;
    theirs_count: number;
    status: "match" | "differs";
};

export type Reconciliation = {
    available: boolean;
    error: string | null;
    matched: number;
    differing: number;
    days: ReconciliationDay[];
};
