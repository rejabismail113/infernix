import { reactive } from 'vue';

export type ToastKind = 'success' | 'error' | 'info';
export interface Toast { id: number; kind: ToastKind; message: string }

const state = reactive<{ items: Toast[] }>({ items: [] });
let seq = 0;

export function useToasts() {
    function push(kind: ToastKind, message: string, ttlMs = 5000) {
        const id = ++seq;
        state.items.push({ id, kind, message });
        if (ttlMs > 0) setTimeout(() => dismiss(id), ttlMs);
        return id;
    }
    function dismiss(id: number) {
        const i = state.items.findIndex((t) => t.id === id);
        if (i >= 0) state.items.splice(i, 1);
    }
    return { items: state.items, push, dismiss, success: (m: string) => push('success', m), error: (m: string) => push('error', m, 8000) };
}
