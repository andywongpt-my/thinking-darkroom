export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

/** P2c — per-photographer BYO-key AI settings (key is write-only). */
export interface AiSettings {
    ai_provider: string | null;
    ai_model: string | null;
    ai_base_url: string | null;
    has_key: boolean;
    providers: Record<string, string>;
    default_models: Record<string, string>;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
