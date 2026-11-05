<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Stmt { bank: string; period: { start: string; end: string }; ledger_opening: string; ledger_closing: string; statement_opening: string; statement_closing: string; difference: string; unmatched_bank: Record<string, unknown>[]; unmatched_ledger: Record<string, unknown>[] }
const props = defineProps<{ bankId: number; periodId: number; statement: Stmt; periods: { id: number; label: string }[] }>();
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
            <table class="text-sm">
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
