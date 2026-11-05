<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ClaimRow } from '@/types/finance';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ claims: LaravelPage<ClaimRow>; filters: Record<string, string | boolean | null>; seesAll: boolean; can: { create: boolean } }>();
const f = reactive({ q: (props.filters.q as string) ?? '', status: (props.filters.status as string) ?? '', mine: Boolean(props.filters.mine) });
const table = useServerTable(() => route('finance.claims.index'), () => props.filters);
const columns: TableColumn[] = [
    { key: 'claim_no', label: 'Claim', sortable: true, hideable: false }, { key: 'staff', label: 'Staff' }, { key: 'purpose', label: 'Purpose' },
    { key: 'total_amount', label: 'Amount', numeric: true, sortable: true }, { key: 'outstanding', label: 'Unpaid', numeric: true }, { key: 'submitted_at', label: 'Submitted', sortable: true }, { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout
        :title="seesAll && !f.mine ? 'Claims' : 'My claims'"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Claims' }]"
    >
        <template #actions>
            <Link
                v-if="can.create"
                :href="route('finance.claims.create')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                New claim
            </Link>
        </template>
        <form
            class="flex flex-wrap items-center gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="table.visit({ ...f, mine: f.mine ? 1 : '', page: 1 })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Claim no. or purpose"
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
                    v-for="s in ['draft', 'pending_approval', 'rejected', 'finance_payable', 'paid', 'closed', 'cancelled']"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace('_', ' ') }}
                </option>
            </select>
            <label
                v-if="seesAll"
                class="flex items-center gap-1"
            ><input
                v-model="f.mine"
                type="checkbox"
            > Only mine</label>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="claims.data"
            :pagination="toPagination(claims)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            prefs-key="finance.claims"
            empty-text="No claims to show."
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-claim_no="{ row }">
                <Link
                    :href="route('finance.claims.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.claim_no }}
                </Link>
            </template>
            <template #cell-total_amount="{ row }">
                <Amount :value="row.total_amount" />
            </template>
            <template #cell-outstanding="{ row }">
                <Amount
                    :value="row.status === 'finance_payable' ? row.outstanding : null"
                    muted
                />
            </template>
            <template #cell-submitted_at="{ row }">
                {{ row.submitted_at?.slice(0, 10) ?? '—' }}
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
