<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ReceiptRow } from '@/types/commercial';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ receipts: LaravelPage<ReceiptRow>; filters: Record<string, string | null>; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
const table = useServerTable(() => route('commercial.receipts.index'), () => props.filters);
const columns: TableColumn[] = [
    { key: 'receipt_no', label: 'Receipt', sortable: true, hideable: false }, { key: 'receipt_date', label: 'Date', sortable: true }, { key: 'payer_name', label: 'Received from' },
    { key: 'method', label: 'Method' }, { key: 'reference', label: 'Reference' }, { key: 'amount', label: 'Amount', numeric: true, sortable: true }, { key: 'unapplied_amount', label: 'Unapplied', numeric: true }, { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout
        title="Receipts"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Receipts' }]"
    >
        <template #actions>
            <Link
                v-if="can.create"
                :href="route('commercial.receipts.create')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Record receipt
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
                placeholder="Receipt no., payer or reference"
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
                </option><option value="posted">
                    Posted
                </option><option value="void">
                    Void
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="receipts.data"
            :pagination="toPagination(receipts)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            prefs-key="commercial.receipts"
            empty-text="No receipts yet."
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-receipt_no="{ row }">
                <Link
                    :href="route('commercial.receipts.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.receipt_no }}
                </Link>
            </template>
            <template #cell-method="{ row }">
                {{ row.method.toUpperCase() }}
            </template>
            <template #cell-amount="{ row }">
                <Amount :value="row.amount" />
            </template>
            <template #cell-unapplied_amount="{ row }">
                <Amount
                    :value="row.unapplied_amount"
                    muted
                />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
