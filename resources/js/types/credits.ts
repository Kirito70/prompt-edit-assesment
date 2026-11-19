export type CreditPackage = {
    id: string;
    amount: string;
};

export type Credits = {
    /** Bought minus settled charges. */
    balance: string;
    /** The balance minus what unfinished generations hold: what can still be spent. */
    available: string;
    packages: CreditPackage[];
};

export type CreditTransactionRow = {
    id: number;
    type: "purchase" | "charge";
    /** Signed: purchases are positive, charges negative. */
    amount: string;
    balance_after: string;
    generation_id: string | null;
    created_at: string;
};
