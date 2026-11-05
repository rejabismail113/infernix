<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { PaymentRow } from '@/types/finance';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ payments: LaravelPage<PaymentRow>; filters: Record<string, string | null>; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
const table = useServerTable(() => route('finance.payments.index'), () => props.filters);
const columns: TableColumn[] = [
    { key: 'payment_no', label: 'Voucher', sortable: true, hideable: false }, { key: 'payment_date', label: 'Date', sortable: true }, { key: 'payee_name', label: 'Payee' },
    { key: 'method', label: 'Method' }, { key: 'bank', label: 'From' }, { key: 'amount', label: 'Amount', numeric: true, sortable: true }, { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout
        title="Payments & vouchers"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Payments' }]"
    >
        <template #actions>
            <Link
                v-if="can.create"
                :href="route('finance.payments.create')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                New payment
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
                placeholder="Voucher no. or payee"
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
                    v-for="s in ['draft', 'pending_approval', 'approved', 'paid', 'cancelled']"
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
            :rows="payments.data"
            :pagination="toPagination(payments)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            prefs-key="finance.payments"
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-payment_no="{ row }">
                <Link
                    :href="route('finance.payments.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.payment_no }}
                </Link>
            </template>
            <template #cell-amount="{ row }">
                <Amount :value="row.amount" />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
