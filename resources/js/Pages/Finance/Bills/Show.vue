<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { BillRow, LinkedDoc } from '@/types/finance';

const props = defineProps<{ bill: BillRow; documents: LinkedDoc[]; can: { submit: boolean; approve: boolean; return: boolean; cancel: boolean; pay: boolean; taxTreatment: boolean } }>();
const approve = useForm({ override_reason: '' });
const tax = useForm({ tax_treatment: props.bill.tax_treatment, note: '' });
const ask = (action: 'return' | 'cancel') => {
    const reason = window.prompt(action === 'return' ? 'Why return this bill to draft?' : 'Why cancel this bill?');
    if (reason) router.post(route(`finance.bills.${action}`, props.bill.id), { reason });
};
</script>

<template>
    <AppLayout
        :title="bill.bill_no"
        :status="bill.status"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bills', href: route('finance.bills.index') }, { label: bill.bill_no }]"
    >
        <template #actions>
            <Button
                v-if="can.submit"
                @click="router.post(route('finance.bills.submit', bill.id))"
            >
                Submit for approval
            </Button>
            <Link
                v-if="can.pay"
                :href="route('finance.payments.create', { target_type: 'bill', target_id: bill.id })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Pay bill
            </Link>
            <Button
                v-if="can.return"
                variant="secondary"
                @click="ask('return')"
            >
                Return to draft
            </Button>
            <Button
                v-if="can.cancel"
                variant="secondary"
                @click="ask('cancel')"
            >
                Cancel
            </Button>
        </template>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Bill"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Vendor
                        </dt><dd>{{ bill.vendor }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Vendor invoice
                        </dt><dd class="font-mono">
                            {{ bill.vendor_invoice_no }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Dates
                        </dt><dd>{{ bill.bill_date }} · due {{ bill.due_date }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Total
                        </dt><dd class="text-lg font-semibold">
                            <Amount :value="bill.total_amount" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Paid
                        </dt><dd><Amount :value="bill.paid_amount" /></dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Outstanding
                        </dt><dd><Amount :value="bill.outstanding" /></dd>
                    </div>
                    <div v-if="bill.journal_id">
                        <dt class="text-surface-500">
                            Ledger
                        </dt><dd>
                            <Link
                                :href="route('finance.journals.show', bill.journal_id)"
                                class="text-brand-700 hover:underline"
                            >
                                Journal #{{ bill.journal_id }}
                            </Link>
                        </dd>
                    </div>
                </dl>
            </Card>
            <Card
                v-if="can.approve"
                :title="`Approval · level ${bill.current_level}`"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="approve.post(route('finance.bills.approve', bill.id), { preserveScroll: true })"
                >
                    <input
                        v-model="approve.override_reason"
                        placeholder="Owner override reason (only if you recorded this bill)"
                        aria-label="Override reason"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <Button type="submit">
                        Approve & post
                    </Button>
                </form>
            </Card>
            <Card
                v-else-if="can.taxTreatment"
                title="Tax treatment"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="tax.put(route('finance.bills.tax-treatment', bill.id), { preserveScroll: true })"
                >
                    <select
                        v-model="tax.tax_treatment"
                        aria-label="Tax treatment"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <option value="pending_review">
                            Requires accountant review
                        </option><option value="deductible">
                            Deductible
                        </option><option value="non_deductible">
                            Non-deductible
                        </option><option value="capital">
                            Capital
                        </option>
                    </select>
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Save
                    </Button>
                </form>
            </Card>
        </div>
        <DataTable
            :columns="[{ key: 'line_no', label: '#', numeric: true }, { key: 'description', label: 'Description' }, { key: 'quantity', label: 'Qty', numeric: true }, { key: 'unit_price', label: 'Unit price', numeric: true }, { key: 'capitalization_class', label: 'Class' }, { key: 'amount', label: 'Amount', numeric: true }]"
            :rows="bill.lines ?? []"
        >
            <template #cell-unit_price="{ row }">
                <Amount :value="row.unit_price" />
            </template>
            <template #cell-amount="{ row }">
                <Amount :value="row.amount" />
            </template>
            <template #cell-capitalization_class="{ row }">
                {{ row.capitalization_class.toLowerCase().replace(/_/g, ' ') }}
            </template>
        </DataTable>
        <Card title="Supplier invoice">
            <EvidencePanel
                entity-type="bill"
                :entity-id="bill.id"
                link-type="invoice"
                :documents="documents"
                :can-attach="bill.status !== 'cancelled'"
            />
        </Card>
    </AppLayout>
</template>
