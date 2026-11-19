export type DashboardStats = {
    total_spent: string;
    spent_this_month: string;
    images: number;
    in_progress: number;
    failed: number;
};

export type DailySpend = {
    date: string;
    amount: string;
};
