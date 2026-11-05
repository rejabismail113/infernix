<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import Stepper from '@/Components/Stepper.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import type { PaymentRow } from '@/types/finance';

const props = defineProps<{ payment: PaymentRow; idempotencyKey: string; can: { submit: boolean; approve: boolean; isMaker: boolean; isOwner: boolean; post: boolean; cancel: boolean } }>();
const steps = [{ key: 'draft', label: 'Draft' }, { key: 'pending_approval', label: 'Approval' }, { key: 'approved', label: 'Approved' }, { key: 'paid', label: 'Paid' }];
const approve = useForm({ override_reason: '' });
const release = useForm({ _idempotency_key: props.idempotencyKey });
function cancel() {
    const reason = window.prompt('Why cancel this payment? Its allocations will be released.');
    if (reason) router.post(route('finance.payments.cancel', props.payment.id), { reason });
}
const targetRoute = (t: string) => (t === 'bill' ? 'finance.bills.show' : 'finance.claims.show');
</script>

<template>
    <AppLayout
        :title="payment.payment_no"
        :status="payment.status"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Payments', href: route('finance.payments.index') }, { label: payment.payment_no }]"
    >
        <template #chips>
            <StatusChip
                v-if="payment.owner_override"
                status="pending"
                label="Owner override"
            />
        </template>
        <template #actions>
            <a
                v-if="payment.voucher_document_id"
                :href="route('documents.download', payment.voucher_document_id)"
                class="rounded-md border border-surface-300 bg-white px-4 py-2 text-sm font-medium hover:bg-surface-50"
            >Download voucher (PDF)</a>
            <Button
                v-if="can.submit"
                @click="router.post(route('finance.payments.submit', payment.id))"
            >
                Submit for approval
            </Button>
            <Button
                v-if="can.post"
                :disabled="release.processing"
                @click="release.post(route('finance.payments.post', payment.id))"
            >
                Release & post payment
            </Button>
            <Button
                v-if="can.cancel"
                variant="secondary"
                @click="cancel"
            >
                Cancel
            </Button>
        </template>
        <Stepper
            v-if="payment.status !== 'cancelled'"
            :steps="steps"
            :current="payment.status"
        />
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Voucher"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Pay to
                        </dt><dd class="font-medium">
                            {{ payment.payee_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Amount
                        </dt><dd class="text-lg font-semibold">
                            <Amount :value="payment.amount" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Date
                        </dt><dd>{{ payment.payment_date }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            From
                        </dt><dd>{{ payment.bank }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Method / ref
                        </dt><dd>{{ payment.method }} {{ payment.reference }}</dd>
                    </div>
                    <div v-if="payment.journal_id">
                        <dt class="text-surface-500">
                            Ledger
                        </dt><dd>
                            <Link
                                :href="route('finance.journals.show', payment.journal_id)"
                                class="text-brand-700 hover:underline"
                            >
                                Journal #{{ payment.journal_id }}
                            </Link>
                        </dd>
                    </div>
                    <div
                        v-if="payment.override_reason"
                        class="sm:col-span-3"
                    >
                        <dt class="text-surface-500">
                            Override reason
                        </dt><dd>{{ payment.override_reason }}</dd>
                    </div>
                </dl>
            </Card>
            <Card
                v-if="can.approve"
                :title="`Approval · level ${payment.current_level}`"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="approve.post(route('finance.payments.approve', payment.id), { preserveScroll: true })"
                >
                    <template v-if="can.isMaker">
                        <p
                            v-if="!can.isOwner"
                            class="text-sm text-amber-700"
                        >
                            You prepared this payment, so someone else must approve it.
                        </p>
                        <input
                            v-else
                            v-model="approve.override_reason"
                            required
                            placeholder="Owner override reason (audited)"
                            aria-label="Override reason"
                            class="w-full rounded-md border border-amber-300 px-3 py-2 text-sm"
                        >
                    </template>
                    <Button
                        type="submit"
                        :disabled="approve.processing || (can.isMaker && !can.isOwner)"
                    >
                        Approve
                    </Button>
                    <p
                        v-if="fieldError(approve.errors, 'general')"
                        class="text-sm text-red-600"
                        role="alert"
                    >
                        {{ fieldError(approve.errors, 'general') }}
                    </p>
                </form>
            </Card>
        </div>
        <DataTable
            :columns="[{ key: 'target_type', label: 'Settles' }, { key: 'reference', label: 'Reference' }, { key: 'amount', label: 'Amount', numeric: true }]"
            :rows="payment.allocations ?? []"
        >
            <template #cell-reference="{ row }">
                <Link
                    :href="route(targetRoute(row.target_type), row.target_id)"
                    class="text-brand-700 hover:underline"
                >
                    {{ row.reference }}
                </Link>
            </template>
            <template #cell-amount="{ row }">
                <Amount :value="row.amount" />
            </template>
        </DataTable>
    </AppLayout>
</template>
