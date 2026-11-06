<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Bucket { count: number; amount: string }
interface Stmt {
    bank: string; period: { start: string; end: string; locked: boolean }; ledger_opening: string; ledger_closing: string; statement_opening: string; statement_closing: string; difference: string;
    money_in: string; money_out: string; running_balance: string | null; running_balance_agrees: boolean | null; opening_balance_brought_forward: string;
    unmatched_bank_total: string; unmatched_ledger_total: string; adjusted_ledger: string; unexplained: string; proven: boolean;
    unmatched_bank: Record<string, unknown>[]; unmatched_ledger: Record<string, unknown>[]; ageing: { as_of: string; buckets: string[]; bank: Record<string, Bucket>; ledger: Record<string, Bucket> };
}
const props = defineProps<{ bankId: number; periodId: number; statement: Stmt; periods: { id: number; label: string }[]; canExport?: boolean }>();
const go = (id: string) => router.get(route('finance.bank.reconciliation', { bank: props.bankId, period: id }));
const isZero = (v: string) => /^-?0(\.0+)?$/.test(v);
</script>

<template>
    <AppLayout
        title="Bank reconciliation"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bank', href: route('finance.bank.show', bankId) }, { label: 'Reconciliation' }]"
    >
        <template #chips>
            <StatusChip
                :status="isZero(statement.difference) && statement.unmatched_bank.length === 0 ? 'complete' : 'pending'"
                :label="isZero(statement.difference) && statement.unmatched_bank.length === 0 ? 'Reconciled' : 'Not reconciled'"
            />
            <StatusChip
                :status="statement.proven ? 'approved' : 'rejected'"
                :label="statement.proven ? 'Balances proven' : 'Unexplained difference'"
                data-test="proof-chip"
            />
        </template>
        <template #actions>
            <select
                :value="periodId"
                aria-label="Period"
                class="rounded-md border border-surface-300 px-2 py-2 text-sm"
                @change="go(($event.target as HTMLSelectElement).value)"
            >
                <option
                    v-for="p in periods"
                    :key="p.id"
                    :value="p.id"
                >
                    {{ p.label }}
                </option>
            </select>
        </template>
        <Card :title="`${statement.bank} · ${statement.period.start} – ${statement.period.end}`">
            <div class="grid gap-6 md:grid-cols-2">
                <table class="text-sm">
                    <caption class="mb-1 text-left font-medium">
                        Statement proof
                    </caption>
                    <tbody>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                Opening balance
                            </td><td class="text-right">
                                <Amount :value="statement.statement_opening" />
                            </td>
                        </tr>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                + Money in
                            </td><td class="text-right">
                                <Amount :value="statement.money_in" />
                            </td>
                        </tr>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                − Money out
                            </td><td class="text-right">
                                <Amount :value="statement.money_out" />
                            </td>
                        </tr>
                        <tr class="border-t font-medium">
                            <td class="pr-8">
                                Closing balance
                            </td><td class="text-right">
                                <Amount :value="statement.statement_closing" />
                            </td>
                        </tr>
                        <tr v-if="statement.running_balance !== null">
                            <td class="pr-8 text-surface-500">
                                Bank's running balance
                            </td>
                            <td class="text-right">
                                <Amount :value="statement.running_balance" /> <span :class="statement.running_balance_agrees ? 'text-emerald-700' : 'text-red-700'">{{ statement.running_balance_agrees ? '✓' : '≠' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table class="text-sm">
                    <caption class="mb-1 text-left font-medium">
                        Book proof
                    </caption>
                    <tbody>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                Ledger balance
                            </td><td class="text-right">
                                <Amount :value="statement.ledger_closing" />
                            </td>
                        </tr>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                + Opening balance brought forward
                            </td><td class="text-right">
                                <Amount :value="statement.opening_balance_brought_forward" />
                            </td>
                        </tr>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                + On statement, not in books
                            </td><td class="text-right">
                                <Amount :value="statement.unmatched_bank_total" />
                            </td>
                        </tr>
                        <tr>
                            <td class="pr-8 text-surface-500">
                                − In books, not on statement
                            </td><td class="text-right">
                                <Amount :value="statement.unmatched_ledger_total" />
                            </td>
                        </tr>
                        <tr class="border-t font-medium">
                            <td class="pr-8">
                                Adjusted balance
                            </td><td class="text-right">
                                <Amount :value="statement.adjusted_ledger" />
                            </td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="pr-8">
                                Unexplained difference
                            </td><td
                                class="text-right"
                                data-test="unexplained"
                            >
                                <Amount :value="statement.unexplained" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <table class="mt-4 text-sm">
                <tbody>
                    <tr>
                        <td class="pr-8 text-surface-500">
                            Statement balance at period end
                        </td><td class="text-right">
                            <Amount :value="statement.statement_closing" />
                        </td>
                    </tr>
                    <tr>
                        <td class="pr-8 text-surface-500">
                            Ledger (book) balance at period end
                        </td><td class="text-right">
                            <Amount :value="statement.ledger_closing" />
                        </td>
                    </tr>
                    <tr class="border-t font-semibold">
                        <td class="pr-8">
                            Difference
                        </td><td class="text-right">
                            <Amount :value="statement.difference" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
        <Card
            :title="`Unmatched ageing at ${statement.ageing.as_of}`"
            class="my-5"
        >
            <table class="w-full text-sm">
                <thead class="text-left text-surface-500">
                    <tr>
                        <th>Side</th><th
                            v-for="b in statement.ageing.buckets"
                            :key="b"
                            class="text-right"
                        >
                            {{ b }} days
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="side in (['bank', 'ledger'] as const)"
                        :key="side"
                        class="border-t border-surface-100"
                    >
                        <td class="py-1">
                            {{ side === 'bank' ? 'Statement lines' : 'Ledger lines' }}
                        </td>
                        <td
                            v-for="b in statement.ageing.buckets"
                            :key="b"
                            class="text-right"
                        >
                            {{ statement.ageing[side][b]?.count ?? 0 }} · <Amount :value="statement.ageing[side][b]?.amount ?? '0.00'" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
        <div class="grid gap-5 xl:grid-cols-2">
            <Card title="Statement lines not in the ledger">
                <DataTable
                    :columns="[{ key: 'date', label: 'Date' }, { key: 'description', label: 'Description' }, { key: 'amount', label: 'Amount', numeric: true }]"
                    :rows="statement.unmatched_bank"
                    empty-text="None."
                >
                    <template #cell-amount="{ row }">
                        <Amount :value="String(row.amount)" />
                    </template>
                </DataTable>
            </Card>
            <Card title="Ledger entries not on the statement">
                <DataTable
                    :columns="[{ key: 'date', label: 'Date' }, { key: 'journal_no', label: 'Journal' }, { key: 'description', label: 'Description' }, { key: 'amount', label: 'Amount', numeric: true }]"
                    :rows="statement.unmatched_ledger"
                    empty-text="None."
                >
                    <template #cell-amount="{ row }">
                        <Amount :value="String(row.amount)" />
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
