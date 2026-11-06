<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { DocRow } from '@/types/commercial';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ type: string; label: string; routeBase: string; documents: LaravelPage<DocRow>; filters: Record<string, string | null>; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', settlement: props.filters.settlement ?? '' });
const table = useServerTable(() => route(`commercial.${props.routeBase}.index`), () => props.filters);
const isInvoice = computed(() => props.type === 'invoice');
const plural = computed(() => (props.type === 'credit_note' ? 'Credit notes' : `${props.label}s`));
const statuses = computed(() => {
    const base = ['draft', 'pending_approval', 'approved', 'issued'];
    if (props.type === 'quotation') return [...base, 'accepted', 'rejected', 'superseded', 'void'];
    return isInvoice.value ? [...base, 'overdue', 'void'] : [...base, 'void'];
});
const columns = computed<TableColumn[]>(() => [
    { key: 'document_no', label: 'Number', sortable: true, hideable: false },
    { key: 'client', label: 'Client' }, { key: 'title', label: 'Title' },
    { key: 'issue_date', label: 'Issued', sortable: true },
    ...(props.type === 'quotation' ? [{ key: 'valid_until', label: 'Valid until' }] : []),
    ...(isInvoice.value ? [{ key: 'due_date', label: 'Due', sortable: true }] : []),
    { key: 'total', label: 'Total', numeric: true, sortable: true },
    ...(isInvoice.value ? [{ key: 'outstanding', label: 'Outstanding', numeric: true }] : []),
    { key: 'status', label: 'Status' },
]);
</script>

<template>
    <AppLayout
        :title="plural"
        :breadcrumbs="[{ label: 'Sales' }, { label: plural }]"
    >
        <template #actions>
            <Link
                v-if="type !== 'credit_note'"
                :href="route('commercial.reports.show', type === 'quotation' ? 'quotation-register' : 'invoice-register')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                {{ type === 'quotation' ? 'Quotation register' : 'Invoice register' }}
            </Link>
            <Link
                v-if="can.create"
                :href="route(`commercial.${routeBase}.create`)"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                data-test="create"
            >
                New {{ label.toLowerCase() }}
            </Link>
        </template>
        <p
            v-if="type === 'credit_note'"
            class="text-sm text-surface-600"
        >
            Credit notes are raised from an issued invoice (open the invoice → “Raise credit note”).
        </p>
        <form
            class="flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="table.visit({ ...f, page: 1 })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Number, title or client"
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
                    v-for="s in statuses"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace(/_/g, ' ') }}
                </option>
            </select>
            <select
                v-if="isInvoice"
                v-model="f.settlement"
                aria-label="Settlement"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any settlement
                </option>
                <option value="unpaid">
                    Unpaid
                </option>
                <option value="partially_paid">
                    Partially paid
                </option>
                <option value="paid">
                    Paid
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="documents.data"
            :pagination="toPagination(documents)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            :prefs-key="`commercial.${type}`"
            :empty-text="`No ${plural.toLowerCase()} yet.`"
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-document_no="{ row }">
                <Link
                    :href="route(`commercial.${routeBase}.show`, row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.number }}
                </Link>
            </template>
            <template #cell-total="{ row }">
                <Amount :value="row.total" />
            </template>
            <template #cell-outstanding="{ row }">
                <Amount
                    v-if="row.outstanding !== null"
                    :value="row.outstanding"
                    muted
                />
            </template>
            <template #cell-status="{ row }">
                <span class="flex flex-wrap gap-1">
                    <StatusChip :status="row.status" />
                    <StatusChip
                        v-if="row.settlement_status && row.status !== 'void'"
                        :status="row.settlement_status === 'paid' ? 'paid' : row.settlement_status === 'partially_paid' ? 'pending' : 'open'"
                        :label="row.settlement_status.replace('_', ' ')"
                    />
                </span>
            </template>
        </DataTable>
    </AppLayout>
</template>
