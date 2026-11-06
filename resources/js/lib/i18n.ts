/**
 * Tiny i18n helper (NFR-12). Keys are the English source text (as in lang/en.json); BM strings come from the
 * shared `i18n.messages` prop. Missing keys fall back to the key itself, so English always renders.
 */
import { usePage } from '@inertiajs/vue3';

export function translate(messages: Record<string, string> | undefined, key: string): string {
    return messages?.[key] ?? key;
}

export function useT(): (key: string) => string {
    let messages: Record<string, string> | undefined;
    try {
        messages = (usePage().props as { i18n?: { messages: Record<string, string> } }).i18n?.messages;
    } catch {
        messages = undefined; // outside Inertia (unit tests)
    }

    return (key: string) => translate(messages, key);
}
