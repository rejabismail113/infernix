<script setup lang="ts">
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

interface Ev { id: number; occurred_at: string; actor: string | null; actor_role: string | null; action: string; entity_type: string; entity_id: number | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null; redactions: string[] | null; ip: string | null; request_id: string | null; reason: string | null }
const props = defineProps<{ events: LaravelPage<Ev>; filters: Record<string, string | null>; actions: string[]; can: { export: boolean } }>();

const f = reactive({ action: props.filters.action ?? '', entity_type: props.filters.entity_type ?? '', entity_id: props.filters.entity_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const table = useServerTable(() => route('admin.audit-log.index'), () => props.filters);
const apply = () => table.visit({ ...f, page: 1 });
const exportUrl = () => route('admin.audit-log.export', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')));

const columns: TableColumn[] = [
    { key: 'occurred_at', label: 'When', sortable: true, hideable: false },
    { key: 'actor', label: 'Actor' },
    { key: 'action', label: 'Action', sortable: true },
    { key: 'entity_type', label: 'Entity', sortable: true },
    { key: 'change', label: 'Before → after' },
    { key: 'reason', label: 'Reason' },
    { key: 'ip', label: 'IP / request', hidden: true },
];
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
                <span class="whitespace-nowrap font-mono text-xs">{{ row.occurred_at.replace('T', ' ').slice(0, 19) }}</span>
            </template>
            <template #cell-actor="{ row }">
                {{ row.actor ?? 'system' }} <span class="block text-xs text-surface-400">{{ row.actor_role }}</span>
            </template>
            <template #cell-action="{ row }">
                <StatusChip
                    :status="row.action"
                    :tone="tone(row.action)"
                    :label="row.action"
                />
            </template>
            <template #cell-entity_type="{ row }">
                {{ row.entity_type }}<span v-if="row.entity_id">#{{ row.entity_id }}</span>
            </template>
            <template #cell-change="{ row }">
                <div class="max-w-md font-mono text-xs">
                    <div
                        v-if="row.before"
                        class="truncate text-red-700"
                        :title="JSON.stringify(row.before)"
                    >
                        − {{ JSON.stringify(row.before) }}
                    </div>
                    <div
                        v-if="row.after"
                        class="truncate text-emerald-700"
                        :title="JSON.stringify(row.after)"
                    >
                        + {{ JSON.stringify(row.after) }}
                    </div>
                    <div
                        v-if="row.redactions?.length"
                        class="text-surface-400"
                    >
                        redacted: {{ row.redactions.join(', ') }}
                    </div>
                </div>
            </template>
            <template #cell-ip="{ row }">
                <span class="text-xs text-surface-500">{{ row.ip }} · {{ row.request_id?.slice(0, 8) }}</span>
            </template>
        </DataTable>
    </AppLayout>
</template>
