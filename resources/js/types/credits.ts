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
