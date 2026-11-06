<script setup lang="ts">
/** Project financials (M02-05) and document chain (M02-02): Lead → Quotation → Client PO/LOA → Contract → Project → Invoices → Receipts. */
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import Icon from '@/Components/Icon.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Doc { id: number; type?: string; number: string; status: string; date: string; total?: string; amount?: string; external_ref?: string | null; outstanding?: string; settlement_status?: string | null; invoice_id?: number }
interface Data {
    project: { id: number; code: string; name: string; client: string | null; contract_value: string; status_delivery: string; status_payment: string; scope: string | null };
    totals: { net_revenue: string; direct_costs: string; contribution: string; margin_percent: string | null; contract_value: string; invoiced: string; received: string };
    by_source: { type: string; source: string; amount: string }[];
    lines: { journal_id: number; journal_no: string; date: string; source_type: string; account: string; type: string; amount: string; description: string | null }[];
    chain: { lead: { id: number; title: string; stage: string; estimated_value: string } | null; quotations: Doc[]; client_pos: Doc[]; contracts: { document_id: number; title: string; link_type: string }[]; invoices: Doc[]; credit_notes: Doc[]; receipts: Doc[] };
}
defineProps<{ data: Data; can: Record<string, boolean> }>();
</script>

<template>
    <AppLayout
        :title="`Financials — ${data.project.code}`"
        :breadcrumbs="[{ label: 'Projects', href: route('projects.projects.index') }, { label: data.project.code, href: route('projects.projects.show', data.project.id) }, { label: 'Financials & chain' }]"
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-surface-200 bg-white p-4">
                <p class="text-sm text-surface-500">
                    Net revenue
                </p><p
                    class="text-2xl font-semibold"
                    data-test="net-revenue"
                >
                    <Amount :value="data.totals.net_revenue" />
                </p>
                <p class="text-xs text-surface-500">
                    Contract <Amount :value="data.totals.contract_value" /> · invoiced <Amount :value="data.totals.invoiced" />
                </p>
            </div>
            <div class="rounded-lg border border-surface-200 bg-white p-4">
                <p class="text-sm text-surface-500">
                    Direct costs
                </p><p
                    class="text-2xl font-semibold"
                    data-test="direct-costs"
                >
                    <Amount :value="data.totals.direct_costs" />
                </p>
                <p class="text-xs text-surface-500">
                    Overhead excluded (§8.1)
                </p>
            </div>
            <div class="rounded-lg border border-surface-200 bg-white p-4">
                <p class="text-sm text-surface-500">
                    Contribution
                </p><p
                    class="text-2xl font-semibold"
                    data-test="contribution"
                >
                    <Amount :value="data.totals.contribution" />
                </p>
                <p class="text-xs text-surface-500">
                    Margin {{ data.totals.margin_percent ?? '—' }}%
                </p>
            </div>
            <div class="rounded-lg border border-surface-200 bg-white p-4">
                <p class="text-sm text-surface-500">
                    Cash received
                </p><p class="text-2xl font-semibold">
                    <Amount :value="data.totals.received" />
                </p>
                <p class="text-xs text-surface-500">
                    Billing: {{ data.project.status_payment.replace('_', ' ') }}
                </p>
            </div>
        </div>

        <Card
            title="Document chain"
            description="Each step links to the next — nothing is re-keyed (M02-02)."
        >
            <ol class="flex flex-wrap items-stretch gap-2 text-sm">
                <li class="min-w-[150px] flex-1 rounded-md border border-surface-200 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Lead
                    </p>
                    <p v-if="data.chain.lead">
                        <Link
                            v-if="can.leads"
                            :href="route('projects.leads.index')"
                            class="text-brand-700 hover:underline"
                        >
                            {{ data.chain.lead.title }}
                        </Link><span v-else>{{ data.chain.lead.title }}</span> <StatusChip
                            :status="data.chain.lead.stage === 'won' ? 'complete' : 'pending'"
                            :label="data.chain.lead.stage"
                        />
                    </p>
                    <p
                        v-else
                        class="text-surface-400"
                    >
                        —
                    </p>
                </li>
                <li class="min-w-[150px] flex-1 rounded-md border border-surface-200 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Quotation
                    </p>
                    <p
                        v-for="q in data.chain.quotations"
                        :key="q.id"
                    >
                        <Link
                            v-if="can.quotations"
                            :href="route('commercial.quotations.show', q.id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ q.number }}
                        </Link><span v-else>{{ q.number }}</span> <StatusChip :status="q.status" />
                    </p>
                    <p
                        v-if="!data.chain.quotations.length"
                        class="text-surface-400"
                    >
                        —
                    </p>
                </li>
                <li class="min-w-[150px] flex-1 rounded-md border border-surface-200 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Client PO / LOA
                    </p>
                    <p
                        v-for="po in data.chain.client_pos"
                        :key="po.id"
                    >
                        <span class="font-mono">{{ po.number }}</span> <span class="text-xs text-surface-500">({{ po.external_ref }})</span>
                    </p>
                    <p
                        v-if="!data.chain.client_pos.length"
                        class="text-surface-400"
                    >
                        —
                    </p>
                </li>
                <li class="min-w-[150px] flex-1 rounded-md border border-surface-200 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Contract
                    </p>
                    <p
                        v-for="c in data.chain.contracts"
                        :key="c.document_id"
                    >
                        <Link
                            :href="route('documents.show', c.document_id)"
                            class="text-brand-700 hover:underline"
                        >
                            {{ c.title }}
                        </Link>
                    </p>
                    <p
                        v-if="!data.chain.contracts.length"
                        class="text-surface-400"
                    >
                        —
                    </p>
                </li>
                <li class="min-w-[150px] flex-1 rounded-md border-2 border-brand-300 bg-brand-50 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Project
                    </p>
                    <p class="font-mono font-medium">
                        {{ data.project.code }}
                    </p>
                    <StatusChip :status="data.project.status_delivery" />
                </li>
                <li class="min-w-[150px] flex-1 rounded-md border border-surface-200 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Invoices
                    </p>
                    <p
                        v-for="i in data.chain.invoices"
                        :key="i.id"
                    >
                        <Link
                            v-if="can.invoices"
                            :href="route('commercial.invoices.show', i.id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ i.number }}
                        </Link><span v-else>{{ i.number }}</span> <Amount :value="i.total ?? '0.00'" />
                    </p>
                    <p
                        v-if="!data.chain.invoices.length"
                        class="text-surface-400"
                    >
                        —
                    </p>
                </li>
                <li class="min-w-[150px] flex-1 rounded-md border border-surface-200 p-3">
                    <p class="text-xs uppercase text-surface-500">
                        Receipts
                    </p>
                    <p
                        v-for="r in data.chain.receipts"
                        :key="`${r.id}-${r.invoice_id}`"
                    >
                        <Link
                            v-if="can.receipts"
                            :href="route('commercial.receipts.show', r.id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ r.number }}
                        </Link><span v-else>{{ r.number }}</span> <Amount :value="r.amount ?? '0.00'" />
                    </p>
                    <p
                        v-if="!data.chain.receipts.length"
                        class="text-surface-400"
                    >
                        —
                    </p>
                </li>
            </ol>
        </Card>

        <div class="grid gap-5 xl:grid-cols-3">
            <Card title="By source">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="s in data.by_source"
                        :key="`${s.type}-${s.source}`"
                        class="flex justify-between py-1.5"
                    >
                        <span><Icon
                            :name="s.type === 'income' ? 'chart' : 'upload'"
                            :size="14"
                            class="mr-1 inline"
                        />{{ s.source }}</span><Amount :value="s.amount" />
                    </li>
                    <li
                        v-if="!data.by_source.length"
                        class="py-2 text-surface-500"
                    >
                        Nothing posted to this project yet.
                    </li>
                </ul>
            </Card>
            <Card
                title="Ledger lines tagged to this project"
                class="xl:col-span-2"
            >
                <DataTable
                    :columns="[{ key: 'date', label: 'Date' }, { key: 'journal_no', label: 'Journal' }, { key: 'account', label: 'Account' }, { key: 'description', label: 'Description' }, { key: 'amount', label: 'Amount', numeric: true }]"
                    :rows="data.lines"
                    empty-text="No postings."
                >
                    <template #cell-journal_no="{ row }">
                        <Link
                            v-if="can.journals"
                            :href="route('finance.journals.show', row.journal_id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ row.journal_no }}
                        </Link><span v-else>{{ row.journal_no }}</span>
                    </template>
                    <template #cell-amount="{ row }">
                        <Amount :value="row.amount" />
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
