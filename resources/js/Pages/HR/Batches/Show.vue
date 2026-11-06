<script setup lang="ts">
/** Payment batch: contractor invoices (M04-05), maker-checker approval, idempotent payout with voucher / payroll file (M04-04). */
import { Link, router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { LinkedDoc } from '@/types/finance';

interface Line { id: number; staff: string; staff_no: string; classification: string; amount: string; timesheet_id: number; period: string; quantity: string; rate: string | null; contractor_invoice_required: boolean; documents: LinkedDoc[] }
const props = defineProps<{
    batch: { id: number; batch_no: string; period_end: string; status: string; payout_method: string; total_amount: string; lines: number; voucher_no: string | null; paid_on: string | null; voucher_document_id: number | null; handoff_document_id: number | null; accrual_journal_id: number | null; payout_journal_id: number | null; current_level: number };
    lines: Line[]; approvals: { level: number; name: string; owner_override: boolean; reason: string | null; decided_at: string }[]; banks: { id: number; label: string }[]; idempotencyKey: string;
    can: { edit: boolean; submit: boolean; approve: boolean; cancel: boolean; pay: boolean; handoff: boolean };
}>();
const approve = useForm({ override_reason: '' });
const pay = useForm({ bank_account_id: props.banks[0]?.id ?? '', paid_on: new Date().toISOString().slice(0, 10) });
const cancel = () => { const r = window.prompt('Why cancel this batch?'); if (r) router.post(route('hr.batches.cancel', props.batch.id), { reason: r }); };
</script>

<template>
    <AppLayout
        :title="`Payment batch ${batch.batch_no}`"
        :status="batch.status"
        :breadcrumbs="[{ label: 'People' }, { label: 'Payment batches', href: route('hr.batches.index') }, { label: batch.batch_no }]"
    >
        <template #actions>
            <a
                v-if="batch.voucher_document_id"
                :href="route('documents.download', { document: batch.voucher_document_id })"
                class="inline-flex items-center gap-1.5 rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
            ><Icon
                name="download"
                :size="16"
            /> Voucher {{ batch.voucher_no }}</a>
            <a
                v-if="can.handoff"
                :href="route('hr.batches.handoff', batch.id)"
                class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 px-3 py-2 text-sm text-amber-800 hover:bg-amber-50"
            ><Icon
                name="download"
                :size="16"
            /> Payroll file (full, audited)</a>
            <Button
                v-if="can.submit"
                data-test="submit-batch"
                @click="router.post(route('hr.batches.submit', batch.id), {}, { preserveScroll: true })"
            >
                Submit for approval
            </Button>
            <Button
                v-if="can.cancel"
                variant="secondary"
                @click="cancel"
            >
                Cancel batch
            </Button>
        </template>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Batch"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-surface-500">
                            Period end
                        </dt><dd>{{ batch.period_end }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Payout
                        </dt><dd>{{ batch.payout_method === 'voucher' ? 'Payment voucher' : 'Payroll handoff file' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Total
                        </dt><dd class="text-lg font-semibold">
                            <Amount :value="batch.total_amount" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Paid
                        </dt><dd>{{ batch.paid_on ?? '—' }}</dd>
                    </div>
                    <div v-if="batch.accrual_journal_id">
                        <dt class="text-surface-500">
                            Ledger
                        </dt><dd>
                            <Link
                                :href="route('finance.journals.show', batch.accrual_journal_id)"
                                class="text-brand-700 hover:underline"
                            >
                                Accrual
                            </Link>
                            <template v-if="batch.payout_journal_id">
                                · <Link
                                    :href="route('finance.journals.show', batch.payout_journal_id)"
                                    class="text-brand-700 hover:underline"
                                >
                                    Payout
                                </Link>
                            </template>
                        </dd>
                    </div>
                </dl>
                <ul
                    v-if="approvals.length"
                    class="mt-3 text-xs text-surface-500"
                >
                    <li
                        v-for="a in approvals"
                        :key="a.level"
                    >
                        Approved L{{ a.level }} by {{ a.name }} {{ a.decided_at.slice(0, 16).replace('T', ' ') }}<span v-if="a.owner_override"> (owner override: {{ a.reason }})</span>
                    </li>
                </ul>
            </Card>
            <Card
                v-if="can.approve"
                :title="`Approval · level ${batch.current_level}`"
                description="You cannot approve a batch you prepared (maker-checker)."
            >
                <form
                    class="space-y-2"
                    @submit.prevent="approve.post(route('hr.batches.approve', batch.id), { preserveScroll: true })"
                >
                    <input
                        v-model="approve.override_reason"
                        placeholder="Owner override reason (only if you prepared it)"
                        aria-label="Override reason"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <Button
                        type="submit"
                        data-test="approve-batch"
                    >
                        Approve & post accrual
                    </Button>
                </form>
            </Card>
            <Card
                v-else-if="can.pay"
                title="Pay out"
            >
                <form
                    class="space-y-2 text-sm"
                    @submit.prevent="pay.post(route('hr.batches.pay', batch.id), { headers: { 'Idempotency-Key': idempotencyKey }, preserveScroll: true })"
                >
                    <select
                        v-model="pay.bank_account_id"
                        aria-label="From bank account"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="b in banks"
                            :key="b.id"
                            :value="b.id"
                        >
                            {{ b.label }}
                        </option>
                    </select>
                    <input
                        v-model="pay.paid_on"
                        type="date"
                        aria-label="Paid on"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                    <Button
                        type="submit"
                        :disabled="pay.processing"
                        data-test="pay-batch"
                    >
                        Mark paid
                    </Button>
                </form>
            </Card>
        </div>
        <Card title="Lines">
            <div
                v-for="l in lines"
                :key="l.id"
                class="border-t border-surface-100 py-3 first:border-0"
            >
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-medium">{{ l.staff }}</span><span class="text-surface-500">{{ l.staff_no }} · {{ l.classification }}</span>
                    <Link
                        :href="route('hr.timesheets.show', l.timesheet_id)"
                        class="text-brand-700 hover:underline"
                    >
                        {{ l.period }}
                    </Link>
                    <span class="text-surface-500">{{ l.quantity }} × <Amount
                        v-if="l.rate"
                        :value="l.rate"
                    /></span>
                    <span class="ml-auto font-semibold"><Amount :value="l.amount" /></span>
                </div>
                <div
                    v-if="l.contractor_invoice_required"
                    class="mt-2 rounded-md bg-amber-50 p-2"
                >
                    <p class="mb-1 text-xs text-amber-800">
                        Contractor invoice required (M04-05)
                    </p>
                    <EvidencePanel
                        entity-type="payment_batch_line"
                        :entity-id="l.id"
                        link-type="invoice"
                        :documents="l.documents"
                        :can-attach="can.edit"
                        compact
                    />
                </div>
                <p
                    v-else
                    class="mt-1 text-xs text-surface-500"
                >
                    Employee — statutory contributions are handled by payroll (handoff file).
                </p>
            </div>
        </Card>
    </AppLayout>
</template>
