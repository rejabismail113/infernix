<script setup lang="ts">
/** Owner Executive Dashboard (M11-01, M11-04): invoiced revenue, cash received, costs incurred and cash paid are four SEPARATE tiles. */
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import MetricInfo from '@/Components/Charts/MetricInfo.vue';
import Amount from '@/Components/Finance/Amount.vue';
import PeriodFilter from '@/Components/Reporting/PeriodFilter.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Metric { name: string; definition: string; source: string; rule: string }
interface Data {
    period: { from: string; to: string };
    tiles: { metric_id: string; name: string; value: string; definition: string }[];
    net_profit: string;
    cash_balance: { total: string; banks: { bank: string; balance: string }[] };
    outstanding_invoices: { total: string; count: number; overdue: string; top: { invoice_id: number; document_no: string; client: string; due_date: string; days_overdue: number; outstanding: string }[] };
    claims: { pending_approval: string; approved_unpaid: string };
    top_projects: { project_id: number; label: string; revenue: string; cost: string; contribution: string }[];
    metrics: Record<string, Metric>;
}
defineProps<{ data: Data; links: Record<string, boolean> }>();
</script>

<template>
    <AppLayout
        title="Owner dashboard"
        :breadcrumbs="[{ label: 'Overview' }, { label: 'Owner dashboard' }]"
    >
        <template #actions>
            <PeriodFilter
                :from="data.period.from"
                :to="data.period.to"
                route-name="reports.dashboard.owner"
            />
        </template>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="t in data.tiles"
                :key="t.metric_id"
                class="rounded-lg border border-surface-200 bg-white p-4"
                :data-test="`tile-${t.metric_id}`"
            >
                <p class="text-sm text-surface-500">
                    {{ t.name }}
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    <Amount :value="t.value" />
                </p>
                <MetricInfo :metric="data.metrics[t.metric_id]" />
            </div>
        </div>
        <p class="text-sm text-surface-600">
            Net profit (accrual) for the period: <strong><Amount :value="data.net_profit" /></strong>
        </p>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card title="Cash balance">
                <p
                    class="text-2xl font-semibold"
                    data-test="cash-total"
                >
                    <Amount :value="data.cash_balance.total" />
                </p>
                <ul class="mt-2 divide-y divide-surface-100 text-sm">
                    <li
                        v-for="b in data.cash_balance.banks"
                        :key="b.bank"
                        class="flex justify-between py-1.5"
                    >
                        <span>{{ b.bank }}</span><Amount :value="b.balance" />
                    </li>
                </ul>
                <MetricInfo :metric="data.metrics.cash_balance" />
            </Card>
            <Card title="Outstanding invoices">
                <p
                    class="text-2xl font-semibold"
                    data-test="ar-total"
                >
                    <Amount :value="data.outstanding_invoices.total" />
                </p>
                <p class="text-sm text-surface-500">
                    {{ data.outstanding_invoices.count }} invoices · overdue <Amount :value="data.outstanding_invoices.overdue" />
                </p>
                <ul class="mt-2 divide-y divide-surface-100 text-sm">
                    <li
                        v-for="i in data.outstanding_invoices.top"
                        :key="i.invoice_id"
                        class="flex justify-between gap-2 py-1.5"
                    >
                        <Link
                            v-if="links.invoices"
                            :href="route('commercial.invoices.show', i.invoice_id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ i.document_no }}
                        </Link>
                        <span class="flex-1 truncate">{{ i.client }}</span>
                        <span
                            v-if="i.days_overdue"
                            class="text-red-700"
                        >{{ i.days_overdue }}d</span>
                        <Amount :value="i.outstanding" />
                    </li>
                </ul>
                <MetricInfo :metric="data.metrics.outstanding_invoices" />
            </Card>
            <Card title="Staff claims">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-surface-500">
                        Pending approval
                    </dt><dd class="text-right font-semibold">
                        <Amount :value="data.claims.pending_approval" />
                    </dd>
                    <dt class="text-surface-500">
                        Approved, unpaid
                    </dt><dd class="text-right font-semibold">
                        <Amount :value="data.claims.approved_unpaid" />
                    </dd>
                </dl>
                <Link
                    v-if="links.claims"
                    :href="route('finance.claims.index')"
                    class="mt-2 inline-block text-sm text-brand-700 hover:underline"
                >
                    Open claims
                </Link>
                <MetricInfo :metric="data.metrics.claims_pending" />
            </Card>
        </div>
        <Card
            title="Top projects by contribution"
            description="Placeholder until project costing arrives (P2): revenue less costs of ledger lines tagged to a project."
        >
            <p
                v-if="!data.top_projects.length"
                class="text-sm text-surface-500"
            >
                No project-tagged postings in this period yet.
            </p>
            <table
                v-else
                class="w-full text-sm"
            >
                <thead class="text-left text-xs uppercase text-surface-500">
                    <tr>
                        <th class="py-1">
                            Project
                        </th><th class="text-right">
                            Revenue
                        </th><th class="text-right">
                            Cost
                        </th><th class="text-right">
                            Contribution
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="p in data.top_projects"
                        :key="p.project_id"
                        class="border-t border-surface-100"
                    >
                        <td class="py-1.5">
                            {{ p.label }}
                        </td><td class="text-right">
                            <Amount :value="p.revenue" />
                        </td><td class="text-right">
                            <Amount :value="p.cost" />
                        </td><td class="text-right font-medium">
                            <Amount :value="p.contribution" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
