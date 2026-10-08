export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    avatar: string | null;
};

export type Auth = {
    user: User | null;
    /** Computed by the server for the current workspace. */
    capabilities: string[];
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
