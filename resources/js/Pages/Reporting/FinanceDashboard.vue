<script setup lang="ts">
/** Finance dashboard (M11-02): monthly P&L, expenses by category, unpaid bills, collections, reconciliation. */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '@/Components/Card.vue';
import MetricInfo from '@/Components/Charts/MetricInfo.vue';
import MoneyChart from '@/Components/Charts/MoneyChart.vue';
import Amount from '@/Components/Finance/Amount.vue';
import PeriodFilter from '@/Components/Reporting/PeriodFilter.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Metric { name: string; definition: string; source: string; rule: string }
interface Data {
    period: { from: string; to: string };
    monthly_pnl: { month: string; income: string; expenses: string; net_profit: string }[];
    expense_by_category: { rows: { code: string; category: string; amount: string }[]; total: string };
    unpaid_bills: { total: string; buckets: Record<string, string>; count: number };
    collections: { month: string; collected: string }[];
    reconciliation: { rows: { bank: string; statement_balance: string; book_balance: string; difference: string; unmatched_lines: number; last_statement_line: string | null }[]; unmatched: number };
    metrics: Record<string, Metric>;
}
const props = defineProps<{ data: Data; links: Record<string, boolean> }>();
const months = computed(() => props.data.monthly_pnl.map((m) => m.month));
const bucketLabels: Record<string, string> = { current: 'Not yet due', d1_30: '1–30 days', d31_60: '31–60 days', d61_90: '61–90 days', d90_plus: '90+ days' };
</script>

<template>
    <AppLayout
        title="Finance dashboard"
        :breadcrumbs="[{ label: 'Overview' }, { label: 'Finance dashboard' }]"
    >
        <template #actions>
            <PeriodFilter
                :from="data.period.from"
                :to="data.period.to"
                route-name="reports.dashboard.finance"
            />
        </template>
        <Card title="Monthly profit & loss (accrual)">
            <MoneyChart
                :labels="months"
                :series="[{ label: 'Income', values: data.monthly_pnl.map((m) => m.income), color: '#16a34a' }, { label: 'Expenses', values: data.monthly_pnl.map((m) => m.expenses), color: '#ea580c' }]"
                description="Monthly income and expenses"
            />
            <MetricInfo :metric="data.metrics.monthly_pnl" />
        </Card>
        <div class="grid gap-5 xl:grid-cols-2">
            <Card title="Collections (official receipts)">
                <MoneyChart
                    kind="line"
                    :height="220"
                    :labels="data.collections.map((c) => c.month)"
                    :series="[{ label: 'Collected', values: data.collections.map((c) => c.collected), color: '#0284c7' }]"
                    description="Cash collected per month"
                />
                <MetricInfo :metric="data.metrics.collections" />
            </Card>
            <Card title="Expenses by category">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="r in data.expense_by_category.rows"
                        :key="r.code"
                        class="flex justify-between py-1.5"
                    >
                        <span>{{ r.category }}</span><Amount :value="r.amount" />
                    </li>
                    <li class="flex justify-between py-1.5 font-semibold">
                        <span>Total</span><Amount :value="data.expense_by_category.total" />
                    </li>
                </ul>
                <MetricInfo :metric="data.metrics.expense_by_category" />
            </Card>
            <Card title="Unpaid bills">
                <p class="text-2xl font-semibold">
                    <Amount :value="data.unpaid_bills.total" />
                </p>
                <p class="text-sm text-surface-500">
                    {{ data.unpaid_bills.count }} bills
                </p>
                <dl class="mt-2 grid grid-cols-2 gap-1 text-sm">
                    <template
                        v-for="(v, k) in data.unpaid_bills.buckets"
                        :key="k"
                    >
                        <dt class="text-surface-500">
                            {{ bucketLabels[k] ?? k }}
                        </dt><dd class="text-right">
                            <Amount :value="v" />
                        </dd>
                    </template>
                </dl>
                <Link
                    v-if="links.bills"
                    :href="route('finance.bills.index')"
                    class="mt-2 inline-block text-sm text-brand-700 hover:underline"
                >
                    Open bills
                </Link>
                <MetricInfo :metric="data.metrics.unpaid_bills" />
            </Card>
            <Card title="Bank reconciliation">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="r in data.reconciliation.rows"
                        :key="r.bank"
                        class="py-2"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ r.bank }}</span>
                            <StatusChip
                                :status="r.unmatched_lines === 0 && /^-?0(\.0+)?$/.test(r.difference) ? 'complete' : 'pending'"
                                :label="r.unmatched_lines === 0 && /^-?0(\.0+)?$/.test(r.difference) ? 'Reconciled' : `${r.unmatched_lines} unmatched`"
                            />
                        </div>
                        <div class="text-xs text-surface-500">
                            Statement <Amount :value="r.statement_balance" /> · Book <Amount :value="r.book_balance" /> · Difference <Amount :value="r.difference" />
                            <span v-if="r.last_statement_line"> · last line {{ r.last_statement_line }}</span>
                        </div>
                    </li>
                </ul>
                <Link
                    v-if="links.bank"
                    :href="route('finance.bank.index')"
                    class="mt-2 inline-block text-sm text-brand-700 hover:underline"
                >
                    Open bank
                </Link>
                <MetricInfo :metric="data.metrics.reconciliation_status" />
            </Card>
        </div>
    </AppLayout>
</template>
