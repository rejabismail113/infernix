export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    app: { name: string; company: { name: string; tagline: string }; currency: string; timezone: string };
    flash: { success: string | null; error: string | null };
    nav: { label: string; href: string; group: string; icon: string }[];
    notifications?: { unread: number };
    i18n?: { locale: 'en' | 'ms'; messages: Record<string, string> };
    [key: string]: unknown;
}
