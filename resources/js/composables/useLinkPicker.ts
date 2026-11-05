import { ref } from 'vue';
import type { PickerItem } from '@/Components/PickerModal.vue';

/** Queries the server for records the user may attach documents to (server applies permissions). */
export function useLinkPicker() {
    const items = ref<PickerItem[]>([]);
    const linkTypes = ref<string[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let seq = 0;

    async function search(type: string | null, q: string) {
        if (!type) return;
        const mine = ++seq;
        loading.value = true;
        error.value = null;
        try {
            const res = await fetch(route('documents.link-targets', { type, q }), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(res.status === 403 ? 'You may not attach documents here.' : 'Search failed.');
            const body = (await res.json()) as { items: PickerItem[]; link_types: string[] };
            if (mine !== seq) return;
            items.value = body.items;
            linkTypes.value = body.link_types;
        } catch (e) {
            if (mine === seq) { items.value = []; error.value = e instanceof Error ? e.message : 'Search failed.'; }
        } finally {
            if (mine === seq) loading.value = false;
        }
    }

    return { items, linkTypes, loading, error, search };
}
