import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import type { TablePagination, TableSort } from '@/types/table';

/** Laravel LengthAwarePaginator JSON (as Inertia receives it). */
export interface LaravelPage<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export function toPagination<T>(p: LaravelPage<T>): TablePagination {
    return { total: p.total, perPage: p.per_page, currentPage: p.current_page, lastPage: p.last_page };
}

/**
 * Wires <DataTable> server mode to Inertia: sort/page/per-page become query params on the same URL.
 * The server whitelists sort keys; the client never sorts or filters a partial page itself.
 */
export function useServerTable(url: () => string, query: () => Record<string, unknown>) {
    const loading = ref(false);
    const offStart = router.on('start', () => { loading.value = true; });
    const offFinish = router.on('finish', () => { loading.value = false; });
    onBeforeUnmount(() => { offStart(); offFinish(); });

    const current = computed(() => query());
    const sort = computed<TableSort | null>(() => {
        const key = current.value.sort;
        return typeof key === 'string' && key !== '' ? { key, dir: current.value.dir === 'asc' ? 'asc' : 'desc' } : null;
    });

    function visit(params: Record<string, unknown>) {
        const merged = Object.fromEntries(Object.entries({ ...current.value, ...params }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        router.get(url(), merged as Record<string, string>, { preserveState: true, preserveScroll: true, replace: true });
    }

    return {
        loading,
        sort,
        onSort: (s: TableSort) => visit({ sort: s.key, dir: s.dir, page: 1 }),
        onPage: (page: number) => visit({ page }),
        onPerPage: (perPage: number) => visit({ per_page: perPage, page: 1 }),
        visit,
    };
}
