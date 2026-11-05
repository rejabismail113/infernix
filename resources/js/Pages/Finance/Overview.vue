<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{
    isFinance: boolean;
    banks: { id: number; label: string; balance: string }[];
    counts: Record<string, number>;
    myClaims: { id: number; claim_no: string; purpose: string; status: string; total_amount: string }[];
    claimsOutstanding: Record<string, string> | null;
}>();
const tiles: { key: string; label: string; route: string; params?: Record<string, string> }[] = [
    { key: 'claims_pending', label: 'Claims awaiting approval', route: 'finance.claims.index', params: { status: 'pending_approval' } },
    { key: 'claims_payable', label: 'Claims approved, unpaid', route: 'finance.claims.index', params: { status: 'finance_payable' } },
    { key: 'bills_pending', label: 'Bills awaiting approval', route: 'finance.bills.index', params: { status: 'pending_approval' } },
    { key: 'bills_unpaid', label: 'Unpaid bills', route: 'finance.bills.index', params: { status: 'approved' } },
    { key: 'payments_to_approve', label: 'Payments to approve', route: 'finance.payments.index', params: { status: 'pending_approval' } },
    { key: 'payments_to_release', label: 'Payments to release', route: 'finance.payments.index', params: { status: 'approved' } },
    { key: 'unmatched_bank', label: 'Unmatched bank lines', route: 'finance.bank.index' },
    { key: 'tax_review', label: 'Entries pending tax review', route: 'finance.transactions.index', params: { tax_treatment: 'pending_review' } },
];
</script>

<template>
    <AppLayout
        title="Finance"
        :breadcrumbs="[{ label: 'Finance' }]"
    >
        <template #actions>
            <Link
                :href="route('finance.claims.create')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                New claim
            </Link>
            <Link
                v-if="isFinance"
                :href="route('finance.transactions.create', { direction: 'outflow' })"
                class="rounded-md border border-surface-300 bg-white px-4 py-2 text-sm font-medium hover:bg-surface-50"
            >
                Record expense
            </Link>
        </template>
        <template v-if="isFinance">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="b in banks"
                    :key="b.id"
                    class="rounded-lg border border-surface-200 bg-white p-4"
                >
                    <p class="text-sm text-surface-500">
                        {{ b.label }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold">
                        <Amount :value="b.balance" />
                    </p>
                    <p class="text-xs text-surface-400">
                        Ledger (book) balance today
                    </p>
                </div>
                <div
                    v-if="claimsOutstanding"
                    class="rounded-lg border border-surface-200 bg-white p-4"
                >
                    <p class="text-sm text-surface-500">
                        Claims owed to staff
                    </p>
                    <p class="mt-1 text-2xl font-semibold">
                        <Amount :value="claimsOutstanding.approved_unpaid" />
                    </p>
                    <p class="text-xs text-surface-400">
                        + <Amount
                            :value="claimsOutstanding.pending_approval"
                            muted
                        /> pending approval
                    </p>
                </div>
            </div>
            <Card title="Needs attention">
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Link
                        v-for="t in tiles"
                        :key="t.key"
                        :href="route(t.route, t.params ?? {})"
                        class="flex items-center justify-between rounded-md border border-surface-200 px-3 py-2 text-sm hover:border-brand-300 hover:bg-brand-50"
                    >
                        {{ t.label }}
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="counts[t.key] ? 'bg-brand-600 text-white' : 'bg-surface-100 text-surface-500'"
                        >{{ counts[t.key] ?? 0 }}</span>
                    </Link>
                </div>
            </Card>
        </template>
        <Card title="My recent claims">
            <ul
                v-if="myClaims.length"
                class="divide-y divide-surface-100 text-sm"
            >
                <li
                    v-for="c in myClaims"
                    :key="c.id"
                    class="flex items-center justify-between py-2"
                >
                    <Link
                        :href="route('finance.claims.show', c.id)"
                        class="font-medium text-brand-700 hover:underline"
                    >
                        {{ c.claim_no }}
                    </Link>
                    <span class="flex-1 px-3 text-surface-600">{{ c.purpose }}</span>
                    <Amount :value="c.total_amount" />
                    <StatusChip
                        :status="c.status"
                        class="ml-3"
                    />
                </li>
            </ul>
            <p
                v-else
                class="text-sm text-surface-500"
            >
                You have no claims yet.
            </p>
        </Card>
    </AppLayout>
</template>
