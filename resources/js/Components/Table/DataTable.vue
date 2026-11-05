<script setup lang="ts" generic="T extends object">
/**
 * Dense data grid (reference: docs/reference "Columns / Density / Export" toolbar).
 * - Server mode (pass `pagination`): emits `sort`, `page`, `per-page`; rows are shown as given.
 * - Client mode (no `pagination`): sorts the given rows locally.
 * Column visibility and density are remembered per `prefsKey` in localStorage (UI preference only).
 * The Export button is a plain link to an audited server export endpoint — never a client-side dump.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '@/Components/Icon.vue';
import type { Density, SortDir, TableColumn, TablePagination, TableSort } from '@/types/table';

const props = withDefaults(defineProps<{
    columns: TableColumn[];
    rows: T[];
    rowKey?: string;
    pagination?: TablePagination | null;
    sort?: TableSort | null;
    exportUrl?: string | null;
    prefsKey?: string | null;
    loading?: boolean;
    emptyText?: string;
    perPageOptions?: number[];
    caption?: string;
}>(), {
    rowKey: 'id', pagination: null, sort: null, exportUrl: null, prefsKey: null, loading: false,
    emptyText: 'No records to show.', perPageOptions: () => [10, 25, 50, 100], caption: undefined,
});

const emit = defineEmits<{
    (e: 'sort', value: TableSort): void;
    (e: 'page', value: number): void;
    (e: 'per-page', value: number): void;
}>();

const DENSITY_CLASS: Record<Density, string> = { compact: 'py-1', normal: 'py-2', comfortable: 'py-3' };
const density = ref<Density>('normal');
const hidden = ref<Set<string>>(new Set(props.columns.filter((c) => c.hidden).map((c) => c.key)));
const localSort = ref<TableSort | null>(props.sort);
const openMenu = ref<'columns' | 'density' | null>(null);
const root = ref<HTMLElement | null>(null);

const visibleColumns = computed(() => props.columns.filter((c) => !hidden.value.has(c.key)));
const activeSort = computed(() => (props.pagination ? props.sort : localSort.value));
const serverMode = computed(() => props.pagination !== null);

const value = (row: T, key: string): unknown => (row as Record<string, unknown>)[key];
const keyOf = (row: T, i: number): string | number => (value(row, props.rowKey) as string | number | undefined) ?? i;

function compare(a: unknown, b: unknown): number {
    if (a === b) return 0;
    if (a === null || a === undefined || a === '') return 1;
    if (b === null || b === undefined || b === '') return -1;
    const na = typeof a === 'string' && /^-?\d+(\.\d+)?$/.test(a) ? Number(a) : a;
    const nb = typeof b === 'string' && /^-?\d+(\.\d+)?$/.test(b) ? Number(b) : b;
    if (typeof na === 'number' && typeof nb === 'number') return na - nb;
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

const displayRows = computed<T[]>(() => {
    if (serverMode.value || !localSort.value) return props.rows;
    const { key, dir } = localSort.value;
    return [...props.rows].sort((x, y) => compare(value(x, key), value(y, key)) * (dir === 'asc' ? 1 : -1));
});

function toggleSort(col: TableColumn) {
    if (!col.sortable) return;
    const dir: SortDir = activeSort.value?.key === col.key && activeSort.value.dir === 'asc' ? 'desc' : 'asc';
    const next = { key: col.key, dir };
    if (serverMode.value) emit('sort', next);
    else localSort.value = next;
}

function ariaSort(col: TableColumn): 'ascending' | 'descending' | 'none' | undefined {
    if (!col.sortable) return undefined;
    if (activeSort.value?.key !== col.key) return 'none';
    return activeSort.value.dir === 'asc' ? 'ascending' : 'descending';
}

function toggleColumn(key: string) {
    const next = new Set(hidden.value);
    if (next.has(key)) next.delete(key);
    else if (visibleColumns.value.length > 1) next.add(key);
    hidden.value = next;
}

const range = computed(() => {
    const p = props.pagination;
    if (!p) return `${props.rows.length} ${props.rows.length === 1 ? 'row' : 'rows'}`;
    if (p.total === 0) return '0 of 0';
    const from = (p.currentPage - 1) * p.perPage + 1;
    return `${from}–${Math.min(p.currentPage * p.perPage, p.total)} of ${p.total}`;
});

// --- preferences (per-viewer convenience only) ---
const storageKey = computed(() => (props.prefsKey ? `infernix.table.${props.prefsKey}` : null));
onMounted(() => {
    if (!storageKey.value) return;
    try {
        const raw = window.localStorage.getItem(storageKey.value);
        if (!raw) return;
        const prefs = JSON.parse(raw) as { hidden?: string[]; density?: Density };
        const known = new Set(props.columns.filter((c) => c.hideable !== false).map((c) => c.key));
        if (Array.isArray(prefs.hidden)) hidden.value = new Set(prefs.hidden.filter((k) => known.has(k)));
        if (prefs.density && prefs.density in DENSITY_CLASS) density.value = prefs.density;
    } catch { /* storage unavailable: defaults */ }
});
watch([hidden, density], () => {
    if (!storageKey.value) return;
    try {
        window.localStorage.setItem(storageKey.value, JSON.stringify({ hidden: [...hidden.value], density: density.value }));
    } catch { /* ignore */ }
});

// --- menus: close on outside click / Escape ---
function onDocClick(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) openMenu.value = null;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') openMenu.value = null;
}
onMounted(() => { document.addEventListener('click', onDocClick); document.addEventListener('keydown', onKey); });
onBeforeUnmount(() => { document.removeEventListener('click', onDocClick); document.removeEventListener('keydown', onKey); });
</script>

<template>
    <div
        ref="root"
        class="overflow-hidden rounded-lg border border-surface-200 bg-white"
    >
        <div class="flex flex-wrap items-center gap-1 border-b border-surface-200 bg-surface-50 px-2 py-1.5 text-sm">
            <div class="relative">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium text-surface-700 hover:bg-surface-100"
                    aria-haspopup="true"
                    :aria-expanded="openMenu === 'columns'"
                    data-test="columns-button"
                    @click.stop="openMenu = openMenu === 'columns' ? null : 'columns'"
                >
                    <Icon
                        name="columns"
                        :size="16"
                    /> Columns
                </button>
                <div
                    v-if="openMenu === 'columns'"
                    class="absolute left-0 z-20 mt-1 w-56 rounded-md border border-surface-200 bg-white p-2 shadow-lg"
                    role="group"
                    aria-label="Visible columns"
                >
                    <label
                        v-for="c in columns"
                        :key="c.key"
                        class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-surface-50"
                    >
                        <input
                            type="checkbox"
                            :checked="!hidden.has(c.key)"
                            :disabled="c.hideable === false"
                            :data-test="`column-toggle-${c.key}`"
                            @change="toggleColumn(c.key)"
                        > {{ c.label }}
                    </label>
                </div>
            </div>
            <div class="relative">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium text-surface-700 hover:bg-surface-100"
                    aria-haspopup="true"
                    :aria-expanded="openMenu === 'density'"
                    data-test="density-button"
                    @click.stop="openMenu = openMenu === 'density' ? null : 'density'"
                >
                    <Icon
                        name="density"
                        :size="16"
                    /> Density
                </button>
                <div
                    v-if="openMenu === 'density'"
                    class="absolute left-0 z-20 mt-1 w-40 rounded-md border border-surface-200 bg-white p-1 shadow-lg"
                    role="menu"
                >
                    <button
                        v-for="d in (['compact', 'normal', 'comfortable'] as Density[])"
                        :key="d"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="density === d"
                        :data-test="`density-${d}`"
                        class="flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm capitalize hover:bg-surface-50"
                        @click="density = d; openMenu = null"
                    >
                        {{ d }} <Icon
                            v-if="density === d"
                            name="check"
                            :size="14"
                        />
                    </button>
                </div>
            </div>
            <a
                v-if="exportUrl"
                :href="exportUrl"
                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium text-surface-700 hover:bg-surface-100"
                data-test="export-link"
            >
                <Icon
                    name="download"
                    :size="16"
                /> Export
            </a>
            <div class="ml-auto flex items-center gap-2">
                <slot name="toolbar" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <caption
                    v-if="caption"
                    class="sr-only"
                >
                    {{ caption }}
                </caption>
                <thead class="bg-white text-left text-xs font-semibold text-surface-600">
                    <tr class="border-b border-surface-200">
                        <th
                            v-for="c in visibleColumns"
                            :key="c.key"
                            scope="col"
                            class="whitespace-nowrap px-3 py-2"
                            :class="[c.align === 'right' || c.numeric ? 'text-right' : c.align === 'center' ? 'text-center' : '']"
                            :style="c.width ? { width: c.width } : undefined"
                            :aria-sort="ariaSort(c)"
                        >
                            <button
                                v-if="c.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 rounded hover:text-surface-900"
                                :data-test="`sort-${c.key}`"
                                @click="toggleSort(c)"
                            >
                                {{ c.label }}
                                <Icon
                                    :name="activeSort?.key === c.key ? (activeSort.dir === 'asc' ? 'sortUp' : 'sortDown') : 'sort'"
                                    :size="12"
                                    :class="activeSort?.key === c.key ? 'text-brand-600' : 'text-surface-300'"
                                />
                            </button>
                            <template v-else>
                                {{ c.label }}
                            </template>
                        </th>
                        <th
                            v-if="$slots.actions"
                            scope="col"
                            class="px-3 py-2"
                        >
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody v-if="loading">
                    <tr
                        v-for="n in 5"
                        :key="n"
                        class="border-b border-surface-100"
                        data-test="skeleton-row"
                    >
                        <td
                            v-for="c in visibleColumns"
                            :key="c.key"
                            class="px-3 py-2.5"
                        >
                            <div class="h-3 animate-pulse rounded bg-surface-200" />
                        </td>
                        <td v-if="$slots.actions" />
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr
                        v-for="(row, i) in displayRows"
                        :key="keyOf(row, i)"
                        class="border-b border-surface-100 last:border-0 hover:bg-surface-50"
                        data-test="row"
                    >
                        <td
                            v-for="c in visibleColumns"
                            :key="c.key"
                            class="px-3 align-top"
                            :class="[DENSITY_CLASS[density], c.align === 'right' || c.numeric ? 'text-right' : c.align === 'center' ? 'text-center' : '', c.numeric ? 'font-mono tabular-nums' : '']"
                        >
                            <slot
                                :name="`cell-${c.key}`"
                                :row="row"
                                :value="value(row, c.key)"
                            >
                                {{ Array.isArray(value(row, c.key)) ? (value(row, c.key) as unknown[]).join(', ') : (value(row, c.key) ?? '—') }}
                            </slot>
                        </td>
                        <td
                            v-if="$slots.actions"
                            class="whitespace-nowrap px-3 text-right"
                            :class="DENSITY_CLASS[density]"
                        >
                            <slot
                                name="actions"
                                :row="row"
                            />
                        </td>
                    </tr>
                    <tr v-if="displayRows.length === 0">
                        <td
                            :colspan="visibleColumns.length + ($slots.actions ? 1 : 0)"
                            class="px-3 py-10 text-center"
                        >
                            <slot name="empty">
                                <p class="text-surface-500">
                                    {{ emptyText }}
                                </p>
                            </slot>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="$slots.footer">
                    <slot name="footer" />
                </tfoot>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-4 border-t border-surface-200 px-3 py-2 text-sm text-surface-600">
            <label
                v-if="pagination"
                class="flex items-center gap-2"
            >
                Rows per page:
                <select
                    class="rounded border border-surface-200 bg-white px-1 py-0.5"
                    :value="pagination.perPage"
                    data-test="per-page"
                    @change="emit('per-page', Number(($event.target as HTMLSelectElement).value))"
                >
                    <option
                        v-for="n in perPageOptions"
                        :key="n"
                        :value="n"
                    >{{ n }}</option>
                </select>
            </label>
            <span data-test="range">{{ range }}</span>
            <div
                v-if="pagination"
                class="flex items-center gap-1"
            >
                <button
                    type="button"
                    class="rounded p-1 hover:bg-surface-100 disabled:opacity-30"
                    :disabled="pagination.currentPage <= 1"
                    aria-label="Previous page"
                    data-test="prev"
                    @click="emit('page', pagination.currentPage - 1)"
                >
                    <Icon name="chevronLeft" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-surface-100 disabled:opacity-30"
                    :disabled="pagination.currentPage >= pagination.lastPage"
                    aria-label="Next page"
                    data-test="next"
                    @click="emit('page', pagination.currentPage + 1)"
                >
                    <Icon name="chevronRight" />
                </button>
            </div>
        </div>
    </div>
</template>
