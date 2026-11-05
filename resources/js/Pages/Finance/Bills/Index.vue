<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { BillRow } from '@/types/finance';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ bills: LaravelPage<BillRow>; filters: Record<string, string | null>; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
const table = useServerTable(() => route('finance.bills.index'), () => props.filters);
const columns: TableColumn[] = [
    { key: 'bill_no', label: 'Bill', sortable: true, hideable: false }, { key: 'vendor', label: 'Vendor' }, { key: 'vendor_invoice_no', label: 'Vendor invoice' },
    { key: 'due_date', label: 'Due', sortable: true }, { key: 'total_amount', label: 'Total', numeric: true, sortable: true }, { key: 'outstanding', label: 'Outstanding', numeric: true }, { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout
        title="Bills (payables)"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bills' }]"
    >
        <template #actions>
            <Link
                :href="route('procurement.vendors.index')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Vendors
            </Link>
            <Link
                v-if="can.create"
                :href="route('finance.bills.create')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Record bill
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
                placeholder="Bill or vendor invoice no."
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
                    v-for="s in ['draft', 'pending_approval', 'approved', 'partially_paid', 'paid', 'cancelled']"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace('_', ' ') }}
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="bills.data"
            :pagination="toPagination(bills)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            prefs-key="finance.bills"
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-bill_no="{ row }">
                <Link
                    :href="route('finance.bills.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.bill_no }}
                </Link>
            </template>
            <template #cell-total_amount="{ row }">
                <Amount :value="row.total_amount" />
            </template>
            <template #cell-outstanding="{ row }">
                <Amount
                    :value="row.outstanding"
                    muted
                />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
