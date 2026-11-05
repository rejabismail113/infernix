<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TransactionRow } from '@/types/finance';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ direction: 'inflow' | 'outflow'; transactions: LaravelPage<TransactionRow>; filters: Record<string, string | null>; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', tax_treatment: props.filters.tax_treatment ?? '' });
const table = useServerTable(() => route('finance.transactions.index'), () => props.filters);
const columns: TableColumn[] = [
    { key: 'transaction_no', label: 'No.', sortable: true, hideable: false }, { key: 'transaction_date', label: 'Date', sortable: true },
    { key: 'description', label: 'Description' }, { key: 'kind', label: props.direction === 'inflow' ? 'Type' : 'Paid by / class' },
    { key: 'gross_amount', label: 'Amount', sortable: true, numeric: true }, { key: 'tax_treatment', label: 'Tax review' }, { key: 'status', label: 'Status' },
];
const label = (v: string | null) => (v ?? '').toLowerCase().replace(/_/g, ' ');
</script>

<template>
    <AppLayout
        :title="direction === 'inflow' ? 'Income & receipts' : 'Expenses'"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: direction === 'inflow' ? 'Income' : 'Expenses' }]"
    >
        <template #actions>
            <Link
                v-if="can.create"
                :href="route('finance.transactions.create', { direction })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                {{ direction === 'inflow' ? 'Record income' : 'Record expense' }}
            </Link>
        </template>
        <div
            role="tablist"
            class="flex w-fit gap-1 rounded-lg bg-white p-1 ring-1 ring-surface-200"
        >
            <Link
                v-for="d in (['outflow', 'inflow'] as const)"
                :key="d"
                role="tab"
                :aria-selected="direction === d"
                :href="route('finance.transactions.index', { direction: d })"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="direction === d ? 'bg-brand-50 text-brand-700' : 'text-surface-600 hover:bg-surface-100'"
            >
                {{ d === 'outflow' ? 'Expenses' : 'Income' }}
            </Link>
        </div>
        <form
            class="flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="table.visit({ ...f, page: 1 })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="No., description, payee"
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
                    v-for="s in ['draft', 'posted', 'reversed', 'cancelled']"
                    :key="s"
                >
                    {{ s }}
                </option>
            </select>
            <select
                v-model="f.tax_treatment"
                aria-label="Tax treatment"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any tax treatment
                </option>
                <option
                    v-for="s in ['pending_review', 'deductible', 'non_deductible', 'capital']"
                    :key="s"
                    :value="s"
                >
                    {{ label(s) }}
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="transactions.data"
            :pagination="toPagination(transactions)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            :prefs-key="`finance.transactions.${direction}`"
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-transaction_no="{ row }">
                <Link
                    :href="route('finance.transactions.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.transaction_no }}
                </Link>
            </template>
            <template #cell-description="{ row }">
                {{ row.description }} <span class="block text-xs text-surface-500">{{ row.counterparty_name }}</span>
            </template>
            <template #cell-kind="{ row }">
                <span class="text-xs">{{ row.direction === 'inflow' ? label(row.inflow_type) : label(row.payment_source) + ' · ' + label(row.capitalization_class) }}</span>
            </template>
            <template #cell-gross_amount="{ row }">
                <Amount :value="row.gross_amount" />
            </template>
            <template #cell-tax_treatment="{ row }">
                <StatusChip
                    :status="row.tax_treatment === 'pending_review' ? 'pending' : 'approved'"
                    :label="label(row.tax_treatment)"
                />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
