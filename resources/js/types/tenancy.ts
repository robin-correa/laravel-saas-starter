export type TenantSummary = {
    id: string;
    name: string | null;
};

export type Tenancy = {
    current: TenantSummary | null;
    available: TenantSummary[];
};
