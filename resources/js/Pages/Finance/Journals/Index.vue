<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { JournalRow } from '@/types/finance';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ journals: LaravelPage<JournalRow>; filters: Record<string, string | null>; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', source_type: props.filters.source_type ?? '' });
const table = useServerTable(() => route('finance.journals.index'), () => props.filters);
const columns: TableColumn[] = [
    { key: 'journal_no', label: 'Journal', sortable: true, hideable: false }, { key: 'posting_date', label: 'Date', sortable: true },
    { key: 'source_type', label: 'Source' }, { key: 'memo', label: 'Memo' }, { key: 'status', label: 'Status' }, { key: 'created_by', label: 'Prepared by', hidden: true },
];
</script>

<template>
    <AppLayout
        title="Journals"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Journals' }]"
    >
        <template #actions>
            <Link
                v-if="can.create"
                :href="route('finance.journals.create')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Adjusting journal
            </Link>
        </template>
        <form
            class="flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="table.visit({ ...f, page: 1 })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Journal no. or memo"
                aria-label="Search"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.status"
                aria-label="Status"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any status
                </option>
                <option
                    v-for="s in ['draft', 'posted', 'reversed']"
                    :key="s"
                >
                    {{ s }}
                </option>
            </select>
            <select
                v-model="f.source_type"
                aria-label="Source"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any source
                </option>
                <option
                    v-for="s in ['transaction', 'claim', 'bill', 'payment', 'manual', 'reversal']"
                    :key="s"
                >
                    {{ s }}
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="journals.data"
            :pagination="toPagination(journals)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            prefs-key="finance.journals"
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-journal_no="{ row }">
                <Link
                    :href="route('finance.journals.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.journal_no }}
                </Link>
            </template>
            <template #cell-source_type="{ row }">
                {{ row.source_type }}<span
                    v-if="row.source_id"
                    class="text-surface-400"
                > #{{ row.source_id }}</span>
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
