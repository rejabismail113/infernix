<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { LinkedDoc, Option, TransactionRow } from '@/types/finance';

const props = defineProps<{
    transaction: TransactionRow & { bank: string | null; lines: { line_no: number; description: string; amount: string; category_id: number | null }[] };
    documents: LinkedDoc[];
    categories: Option[];
    can: { post: boolean; reverse: boolean; cancel: boolean; taxTreatment: boolean; attach: boolean };
}>();
const tax = useForm({ tax_treatment: props.transaction.tax_treatment, note: '' });
const category = (id: number | null) => props.categories.find((c) => c.id === id)?.label ?? 'General';
const ask = (action: 'reverse' | 'cancel') => {
    const reason = window.prompt(action === 'reverse' ? 'Why reverse this entry? The correction posts today. (audited)' : 'Why cancel this draft?');
    if (reason) router.post(route(`finance.transactions.${action}`, props.transaction.id), { reason });
};
const human = (v: string | null) => (v ?? '').toLowerCase().replace(/_/g, ' ');
</script>

<template>
    <AppLayout
        :title="transaction.transaction_no"
        :status="transaction.status"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: transaction.direction === 'inflow' ? 'Income' : 'Expenses', href: route('finance.transactions.index', { direction: transaction.direction }) }, { label: transaction.transaction_no }]"
    >
        <template #actions>
            <Button
                v-if="can.post"
                @click="router.post(route('finance.transactions.post', transaction.id))"
            >
                Post to ledger
            </Button>
            <Button
                v-if="can.cancel"
                variant="secondary"
                @click="ask('cancel')"
            >
                Cancel draft
            </Button>
            <Button
                v-if="can.reverse"
                variant="danger"
                @click="ask('reverse')"
            >
                Reverse…
            </Button>
        </template>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Details"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Date
                        </dt><dd>{{ transaction.transaction_date }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Amount
                        </dt><dd class="text-lg font-semibold">
                            <Amount :value="transaction.gross_amount" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            {{ transaction.direction === 'inflow' ? 'Type' : 'Paid by · class' }}
                        </dt><dd>{{ transaction.direction === 'inflow' ? human(transaction.inflow_type) : human(transaction.payment_source) + ' · ' + human(transaction.capitalization_class) }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Bank
                        </dt><dd>{{ transaction.bank ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Counterparty
                        </dt><dd>{{ transaction.counterparty_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Ledger
                        </dt><dd>
                            <Link
                                v-if="transaction.journal_id"
                                :href="route('finance.journals.show', transaction.journal_id)"
                                class="text-brand-700 hover:underline"
                            >
                                Journal #{{ transaction.journal_id }}
                            </Link>
                            <span v-else>not posted</span>
                            <Link
                                v-if="transaction.reversal_journal_id"
                                :href="route('finance.journals.show', transaction.reversal_journal_id)"
                                class="ml-2 text-brand-700 hover:underline"
                            >
                                reversal #{{ transaction.reversal_journal_id }}
                            </Link>
                        </dd>
                    </div>
                    <div class="sm:col-span-3">
                        <dt class="text-surface-500">
                            Description
                        </dt><dd>{{ transaction.description ?? '—' }}</dd>
                    </div>
                </dl>
            </Card>
            <Card
                title="Tax treatment"
                description="Accountant review flag. Does not change the ledger."
            >
                <form
                    v-if="can.taxTreatment"
                    class="space-y-2"
                    @submit.prevent="tax.put(route('finance.transactions.tax-treatment', transaction.id), { preserveScroll: true })"
                >
                    <select
                        v-model="tax.tax_treatment"
                        aria-label="Tax treatment"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <option value="pending_review">
                            Requires accountant review
                        </option>
                        <option value="deductible">
                            Deductible
                        </option>
                        <option value="non_deductible">
                            Non-deductible
                        </option>
                        <option value="capital">
                            Capital
                        </option>
                    </select>
                    <input
                        v-model="tax.note"
                        placeholder="Note (optional)"
                        aria-label="Note"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Save
                    </Button>
                </form>
                <p
                    v-else
                    class="text-sm"
                >
                    {{ transaction.tax_treatment === 'pending_review' ? 'Requires accountant review' : human(transaction.tax_treatment) }}
                </p>
            </Card>
        </div>
        <DataTable
            :columns="[{ key: 'line_no', label: '#', numeric: true }, { key: 'description', label: 'Description' }, { key: 'category', label: 'Category' }, { key: 'amount', label: 'Amount', numeric: true }]"
            :rows="transaction.lines"
        >
            <template #cell-category="{ row }">
                {{ category(row.category_id) }}
            </template>
            <template #cell-amount="{ row }">
                <Amount :value="row.amount" />
            </template>
        </DataTable>
        <Card
            title="Evidence"
            description="Receipts and supplier invoices for the accountant pack."
        >
            <EvidencePanel
                entity-type="transaction"
                :entity-id="transaction.id"
                :documents="documents"
                :can-attach="can.attach"
            />
        </Card>
    </AppLayout>
</template>
