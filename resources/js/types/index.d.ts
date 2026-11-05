export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    app: { name: string; company: { name: string; tagline: string }; currency: string; timezone: string };
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}
