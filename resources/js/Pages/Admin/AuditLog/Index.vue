<script setup lang="ts">
import { reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import { describeChanges, entityLabel, humanize } from '@/lib/auditDiff';
import type { TableColumn } from '@/types/table';

interface Ev { id: number; occurred_at: string; actor: string | null; actor_role: string | null; action: string; entity_type: string; entity_id: number | null; before: Record<string, unknown> | unknown[] | null; after: Record<string, unknown> | unknown[] | null; redactions: string[] | null; ip: string | null; request_id: string | null; reason: string | null }
const props = defineProps<{ events: LaravelPage<Ev>; people?: Record<string, string>; filters: Record<string, string | null>; actions: string[]; can: { export: boolean } }>();

const f = reactive({ action: props.filters.action ?? '', entity_type: props.filters.entity_type ?? '', entity_id: props.filters.entity_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const table = useServerTable(() => route('admin.audit-log.index'), () => props.filters);
const apply = () => table.visit({ ...f, page: 1 });
const exportUrl = () => route('admin.audit-log.export', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')));

const columns: TableColumn[] = [
    { key: 'occurred_at', label: 'When', sortable: true, hideable: false },
    { key: 'actor', label: 'Actor' },
    { key: 'action', label: 'Action', sortable: true },
    { key: 'entity_type', label: 'Entity', sortable: true },
    { key: 'change', label: 'What changed' },
    { key: 'reason', label: 'Reason' },
    { key: 'ip', label: 'IP / request', hidden: true },
];
// Readable change rows (lib/auditDiff); the first few are shown, the rest behind "Show N more". Raw JSON stays in the CSV export.
const VISIBLE = 4;
const expanded = ref(new Set<number>());
const toggle = (id: number) => {
    const next = new Set(expanded.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    expanded.value = next;
};
const changesFor = (row: Ev) => describeChanges(row.before, row.after, props.people ?? {});
const tone = (a: string) => (a.includes('fail') || a.includes('denied') || a.includes('rejected') || a === 'hard_delete' ? 'danger' : a.includes('permission') || a.includes('lock') || a === 'reveal' || a === 'export' ? 'warning' : 'neutral');
</script>

<template>
    <AppLayout
        title="Audit log"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Audit log' }]"
    >
        <template #chips>
            <StatusChip
                status="info"
                tone="info"
                :label="`${events.total} events`"
            />
        </template>
        <form
            class="flex flex-wrap items-end gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="apply"
        >
            <select
                v-model="f.action"
                aria-label="Action"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any action
                </option>
                <option
                    v-for="a in actions"
                    :key="a"
                >
                    {{ a }}
                </option>
            </select>
            <input
                v-model="f.entity_type"
                placeholder="Entity type"
                aria-label="Entity type"
                class="rounded-md border border-surface-300 px-3 py-2"
            >
            <input
                v-model="f.entity_id"
                placeholder="Entity id"
                aria-label="Entity id"
                class="w-28 rounded-md border border-surface-300 px-3 py-2"
            >
            <input
                v-model="f.from"
                type="date"
                aria-label="From"
                class="rounded-md border border-surface-300 px-3 py-2"
            >
            <input
                v-model="f.to"
                type="date"
                aria-label="To"
                class="rounded-md border border-surface-300 px-3 py-2"
            >
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="events.data"
            :pagination="toPagination(events)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            :export-url="can.export ? exportUrl() : null"
            prefs-key="admin.audit-log"
            caption="Audit events"
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-occurred_at="{ row }">
                <span class="whitespace-nowrap text-sm tabular-nums text-surface-700">{{ row.occurred_at.replace('T', ' ').slice(0, 19) }}</span>
            </template>
            <template #cell-actor="{ row }">
                {{ row.actor ?? 'system' }} <span class="block text-xs text-surface-400">{{ row.actor_role }}</span>
            </template>
            <template #cell-action="{ row }">
                <StatusChip
                    :status="row.action"
                    :tone="tone(row.action)"
                    :label="humanize(row.action)"
                />
            </template>
            <template #cell-entity_type="{ row }">
                {{ entityLabel(row.entity_type) }}<span
                    v-if="row.entity_id"
                    class="text-surface-500"
                > #{{ row.entity_id }}</span>
            </template>
            <template #cell-change="{ row }">
                <div
                    v-for="(c, i) in changesFor(row)"
                    v-show="i < VISIBLE || expanded.has(row.id)"
                    :key="c.key"
                    class="max-w-xl py-0.5 text-sm leading-snug"
                >
                    <span class="font-medium text-surface-700">{{ c.label }}:</span>
                    <template v-if="c.kind === 'changed'">
                        <span class="ml-1 text-surface-500 line-through decoration-red-400/70">{{ c.from }}</span>
                        <span
                            class="mx-1 text-surface-400"
                            aria-label="changed to"
                        >→</span>
                        <span class="font-medium text-emerald-700">{{ c.to }}</span>
                    </template>
                    <span
                        v-else-if="c.kind === 'added'"
                        class="ml-1 text-surface-900"
                    >{{ c.to }}</span>
                    <span
                        v-else
                        class="ml-1 text-surface-500"
                    ><span class="line-through decoration-red-400/70">{{ c.from }}</span> <span class="text-xs">(removed)</span></span>
                </div>
                <button
                    v-if="changesFor(row).length > VISIBLE"
                    type="button"
                    class="mt-1 text-xs font-medium text-brand-700 hover:underline"
                    :aria-expanded="expanded.has(row.id)"
                    @click="toggle(row.id)"
                >
                    {{ expanded.has(row.id) ? 'Show less' : `Show ${changesFor(row).length - VISIBLE} more` }}
                </button>
                <span
                    v-if="changesFor(row).length === 0"
                    class="text-sm text-surface-400"
                >—</span>
                <p
                    v-if="row.redactions?.length"
                    class="mt-1 text-xs text-surface-500"
                >
                    Hidden for privacy: {{ row.redactions.map(humanize).join(', ') }}
                </p>
            </template>
            <template #cell-ip="{ row }">
                <span class="text-xs text-surface-500">{{ row.ip }} · {{ row.request_id?.slice(0, 8) }}</span>
            </template>
        </DataTable>
    </AppLayout>
</template>
