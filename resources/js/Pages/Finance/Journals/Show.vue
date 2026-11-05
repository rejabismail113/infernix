<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { JournalRow } from '@/types/finance';

const props = defineProps<{ journal: JournalRow; can: { reverse: boolean; approve: boolean } }>();
const sourceRoute: Record<string, string> = { transaction: 'finance.transactions.show', claim: 'finance.claims.show', bill: 'finance.bills.show', payment: 'finance.payments.show', reversal: 'finance.journals.show' };
function reverse() {
    const reason = window.prompt('Why is this journal being reversed? (audited)');
    if (reason) router.post(route('finance.journals.reverse', props.journal.id), { reason });
}
function approve() {
    router.post(route('finance.journals.approve', props.journal.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout
        :title="journal.journal_no"
        :status="journal.status"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Journals', href: route('finance.journals.index') }, { label: journal.journal_no }]"
    >
        <template #actions>
            <Button
                v-if="can.approve"
                @click="approve"
            >
                Approve & post
            </Button>
            <Button
                v-if="can.approve"
                variant="secondary"
                @click="router.delete(route('finance.journals.destroy', journal.id))"
            >
                Discard draft
            </Button>
            <Button
                v-if="can.reverse"
                variant="danger"
                @click="reverse"
            >
                Reverse…
            </Button>
        </template>
        <Card>
            <dl class="grid gap-2 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-surface-500">
                        Posting date
                    </dt><dd>{{ journal.posting_date }}</dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Source
                    </dt><dd>
                        <Link
                            v-if="journal.source_id && sourceRoute[journal.source_type]"
                            :href="route(sourceRoute[journal.source_type], journal.source_id)"
                            class="text-brand-700 hover:underline"
                        >
                            {{ journal.source_type }} #{{ journal.source_id }}
                        </Link>
                        <span v-else>{{ journal.source_type }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Prepared by
                    </dt><dd>{{ journal.created_by }}</dd>
                </div>
                <div v-if="journal.reversed_by_id || journal.reversal_of_id">
                    <dt class="text-surface-500">
                        {{ journal.reversed_by_id ? 'Reversed by' : 'Reverses' }}
                    </dt><dd>
                        <Link
                            :href="route('finance.journals.show', (journal.reversed_by_id ?? journal.reversal_of_id) as number)"
                            class="text-brand-700 hover:underline"
                        >
                            journal #{{ journal.reversed_by_id ?? journal.reversal_of_id }}
                        </Link>
                    </dd>
                </div>
                <div class="sm:col-span-4">
                    <dt class="text-surface-500">
                        Memo
                    </dt><dd>{{ journal.memo }}</dd>
                </div>
            </dl>
        </Card>
        <DataTable
            :columns="[{ key: 'line_no', label: '#', numeric: true }, { key: 'account', label: 'Account' }, { key: 'description', label: 'Description' }, { key: 'debit', label: 'Debit', numeric: true }, { key: 'credit', label: 'Credit', numeric: true }]"
            :rows="journal.lines ?? []"
        >
            <template #cell-debit="{ row }">
                <Amount
                    v-if="row.debit !== '0.00'"
                    :value="row.debit"
                />
            </template>
            <template #cell-credit="{ row }">
                <Amount
                    v-if="row.credit !== '0.00'"
                    :value="row.credit"
                />
            </template>
            <template #footer>
                <tr class="border-t border-surface-200 font-semibold">
                    <td
                        colspan="3"
                        class="px-3 py-2 text-right"
                    >
                        Total
                    </td>
                    <td class="px-3 py-2 text-right">
                        <Amount :value="journal.total" />
                    </td>
                    <td class="px-3 py-2 text-right">
                        <Amount :value="journal.total" />
                    </td>
                </tr>
            </template>
        </DataTable>
    </AppLayout>
</template>
