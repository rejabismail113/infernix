<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Row { id: number; staff: string; staff_no: string; period_start: string; period_end: string; status: string; total_quantity: string; rate: string | null; amount: string | null; classification: string }
const props = defineProps<{ mine: Row[]; toVerify: Row[]; toCalculate: Row[]; all: Row[]; me: { id: number; name: string } | null; can: { create: boolean } }>();
const tabs = [
    ...(props.me ? [{ key: 'mine', label: 'My timesheets' }] : []),
    ...(props.toVerify.length ? [{ key: 'toVerify', label: `To verify (${props.toVerify.length})` }] : []),
    ...(props.toCalculate.length ? [{ key: 'toCalculate', label: 'Verified / calculated' }] : []),
    ...(props.all.length ? [{ key: 'all', label: 'All' }] : []),
] as { key: 'mine' | 'toVerify' | 'toCalculate' | 'all'; label: string }[];
const tab = ref(tabs[0]?.key ?? 'mine');
const now = new Date();
const first = new Date(now.getFullYear(), now.getMonth(), 1);
const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const form = useForm({ period_start: iso(first), period_end: iso(last) });
const columns = [{ key: 'period', label: 'Period', hideable: false }, { key: 'staff', label: 'Staff' }, { key: 'total_quantity', label: 'Quantity', numeric: true }, { key: 'amount', label: 'Amount', numeric: true }, { key: 'status', label: 'Status' }];
</script>

<template>
    <AppLayout
        title="Timesheets"
        :breadcrumbs="[{ label: 'People' }, { label: 'Timesheets' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'hr.timesheet-summary')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Timesheet summary
            </Link>
        </template>
        <Card
            v-if="can.create"
            title="New timesheet"
            description="Book time only to projects you are assigned to (UAT-07)."
        >
            <form
                class="flex flex-wrap items-end gap-2 text-sm"
                @submit.prevent="form.post(route('hr.timesheets.store'))"
            >
                <label class="flex flex-col text-xs text-surface-500">From <input
                    v-model="form.period_start"
                    type="date"
                    class="rounded-md border border-surface-300 px-2 py-2 text-sm text-surface-800"
                ></label>
                <label class="flex flex-col text-xs text-surface-500">To <input
                    v-model="form.period_end"
                    type="date"
                    class="rounded-md border border-surface-300 px-2 py-2 text-sm text-surface-800"
                ></label>
                <Button
                    type="submit"
                    data-test="new-timesheet"
                    :disabled="form.processing"
                >
                    Start timesheet
                </Button>
                <span
                    v-if="form.errors.period_end"
                    class="text-red-700"
                >{{ form.errors.period_end }}</span>
            </form>
        </Card>
        <nav
            v-if="tabs.length > 1"
            class="flex gap-1 border-b border-surface-200 text-sm"
            role="tablist"
        >
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="tab === t.key"
                class="-mb-px border-b-2 px-4 py-2"
                :class="tab === t.key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-surface-500'"
                @click="tab = t.key"
            >
                {{ t.label }}
            </button>
        </nav>
        <DataTable
            :columns="columns"
            :rows="props[tab]"
            :empty-text="tab === 'mine' ? 'No timesheets yet.' : 'Nothing here.'"
        >
            <template #cell-period="{ row }">
                <Link
                    :href="route('hr.timesheets.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.period_start }} → {{ row.period_end }}
                </Link>
            </template>
            <template #cell-amount="{ row }">
                <Amount
                    v-if="row.amount"
                    :value="row.amount"
                />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
