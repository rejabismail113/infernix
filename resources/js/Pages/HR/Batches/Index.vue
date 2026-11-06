<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { sumDecimals } from '@/lib/decimal';

interface Batch { id: number; batch_no: string; period_end: string; status: string; payout_method: string; total_amount: string; lines: number; voucher_no: string | null }
interface Ts { id: number; staff: string; staff_no: string; period_start: string; period_end: string; amount: string | null; classification: string }
const props = defineProps<{ batches: Batch[]; calculated: Ts[]; can: { create: boolean } }>();
const form = useForm({ timesheet_ids: [] as number[], period_end: new Date().toISOString().slice(0, 10), payout_method: 'voucher' });
const selectedTotal = computed(() => sumDecimals(props.calculated.filter((t) => form.timesheet_ids.includes(t.id)).map((t) => t.amount ?? '0')));
</script>

<template>
    <AppLayout
        title="Payment batches"
        :breadcrumbs="[{ label: 'People' }, { label: 'Payment batches' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'hr.payment-history')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Payment history
            </Link>
        </template>
        <Card
            v-if="can.create"
            title="New batch from calculated timesheets"
            description="Contractors must have their invoice attached before the batch can be submitted (M04-05)."
        >
            <p
                v-if="!calculated.length"
                class="text-sm text-surface-500"
            >
                No calculated timesheets waiting.
            </p>
            <form
                v-else
                class="space-y-3 text-sm"
                @submit.prevent="form.post(route('hr.batches.store'))"
            >
                <table class="w-full">
                    <tbody>
                        <tr
                            v-for="t in calculated"
                            :key="t.id"
                            class="border-t border-surface-100"
                        >
                            <td class="w-8 py-1.5">
                                <input
                                    v-model="form.timesheet_ids"
                                    type="checkbox"
                                    :value="t.id"
                                    :aria-label="`Include ${t.staff}`"
                                >
                            </td>
                            <td>{{ t.staff }} <span class="text-surface-500">({{ t.staff_no }}, {{ t.classification }})</span></td>
                            <td>{{ t.period_start }} → {{ t.period_end }}</td>
                            <td class="text-right">
                                <Amount :value="t.amount ?? '0.00'" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="flex flex-col text-xs text-surface-500">Period end <input
                        v-model="form.period_end"
                        type="date"
                        class="rounded-md border border-surface-300 px-2 py-2 text-sm text-surface-800"
                    ></label>
                    <label class="flex flex-col text-xs text-surface-500">Payout <select
                        v-model="form.payout_method"
                        class="rounded-md border border-surface-300 px-2 py-2 text-sm text-surface-800"
                    ><option value="voucher">Payment voucher</option><option value="payroll_csv">Payroll handoff file</option></select></label>
                    <span class="ml-auto">Selected: <strong><Amount :value="selectedTotal" /></strong></span>
                    <Button
                        type="submit"
                        :disabled="!form.timesheet_ids.length || form.processing"
                        data-test="create-batch"
                    >
                        Create batch
                    </Button>
                </div>
            </form>
        </Card>
        <DataTable
            :columns="[{ key: 'batch_no', label: 'Batch', hideable: false }, { key: 'period_end', label: 'Period end' }, { key: 'lines', label: 'Staff', numeric: true }, { key: 'payout_method', label: 'Payout' }, { key: 'total_amount', label: 'Total', numeric: true }, { key: 'status', label: 'Status' }]"
            :rows="batches"
            empty-text="No batches yet."
        >
            <template #cell-batch_no="{ row }">
                <Link
                    :href="route('hr.batches.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.batch_no }}
                </Link>
            </template>
            <template #cell-payout_method="{ row }">
                {{ row.payout_method === 'voucher' ? `Voucher ${row.voucher_no ?? ''}` : 'Payroll file' }}
            </template>
            <template #cell-total_amount="{ row }">
                <Amount :value="row.total_amount" />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
