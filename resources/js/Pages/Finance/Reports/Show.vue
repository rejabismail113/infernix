<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Option, ReportData } from '@/types/finance';
import type { TableColumn } from '@/types/table';

const props = defineProps<{ report: string; name: string; data: ReportData; filters: Record<string, string | null>; accounts: Option[]; canExport: boolean }>();
const f = reactive({ ...props.filters });
const usesRange = ['general-ledger', 'profit-and-loss', 'cash-movement', 'expenses-by-category', 'owner-statement'].includes(props.report);
const usesAsOf = ['trial-balance', 'payables-ageing', 'receivables-ageing'].includes(props.report);
const columns = computed<TableColumn[]>(() => props.data.columns.map((c) => ({ key: c.key, label: c.label, numeric: c.numeric, sortable: true })));
const numericKeys = computed(() => new Set(props.data.columns.filter((c) => c.numeric).map((c) => c.key)));
const apply = () => router.get(route('finance.reports.show', props.report), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '')), { preserveState: true });
const exportUrl = computed(() => route('finance.reports.export', { report: props.report, ...Object.fromEntries(Object.entries(props.filters).filter(([, v]) => v !== null && v !== '')) }));
const sourceRoute: Record<string, string> = { transaction: 'finance.transactions.show', claim: 'finance.claims.show', bill: 'finance.bills.show', payment: 'finance.payments.show' };
</script>

<template>
    <AppLayout
        :title="name"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Reports', href: route('finance.reports.index') }, { label: name }]"
    >
        <form
            class="flex flex-wrap items-end gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            @submit.prevent="apply"
        >
            <template v-if="usesRange">
                <label class="flex flex-col">From <input
                    v-model="f.from"
                    type="date"
                    class="rounded-md border border-surface-300 px-3 py-2"
                ></label>
                <label class="flex flex-col">To <input
                    v-model="f.to"
                    type="date"
                    class="rounded-md border border-surface-300 px-3 py-2"
                ></label>
            </template>
            <label
                v-if="usesAsOf"
                class="flex flex-col"
            >As at <input
                v-model="f.as_of"
                type="date"
                class="rounded-md border border-surface-300 px-3 py-2"
            ></label>
            <label
                v-if="report === 'profit-and-loss'"
                class="flex flex-col"
            >Basis <select
                v-model="f.basis"
                class="rounded-md border border-surface-300 px-2 py-2"
            ><option value="accrual">Accrual</option><option value="cash">Cash (indicative)</option></select></label>
            <label
                v-if="report === 'general-ledger'"
                class="flex flex-col"
            >Account <select
                v-model="f.account_id"
                class="rounded-md border border-surface-300 px-2 py-2"
            ><option
                v-for="a in accounts"
                :key="a.id"
                :value="a.id"
            >{{ a.label }}</option></select></label>
            <Button type="submit">
                Run
            </Button>
        </form>
        <h2 class="text-sm font-medium text-surface-600">
            {{ data.title }}
        </h2>
        <DataTable
            :columns="columns"
            :rows="data.rows"
            :export-url="canExport ? exportUrl : null"
            :prefs-key="`finance.report.${report}`"
            empty-text="Nothing to report for these filters."
        >
            <template
                v-for="k in numericKeys"
                :key="k"
                #[`cell-${k}`]="{ value }"
            >
                <Amount
                    v-if="typeof value === 'string' && /^-?\d/.test(value)"
                    :value="value"
                />
                <span v-else>{{ value }}</span>
            </template>
            <template #cell-journal_no="{ row }">
                <Link
                    :href="route('finance.journals.show', Number(row.journal_id))"
                    class="font-mono text-brand-700 hover:underline"
                >
                    {{ row.journal_no }}
                </Link>
            </template>
            <template #cell-source="{ row }">
                <Link
                    v-if="row.source_id && sourceRoute[String(row.source_type)]"
                    :href="route(sourceRoute[String(row.source_type)], Number(row.source_id))"
                    class="text-brand-700 hover:underline"
                >
                    {{ row.source }}
                </Link>
                <span v-else>{{ row.source }}</span>
            </template>
            <template #cell-claim_no="{ row }">
                <Link
                    :href="route('finance.claims.show', Number(row.claim_id))"
                    class="text-brand-700 hover:underline"
                >
                    {{ row.claim_no }}
                </Link>
            </template>
            <template #cell-bill_no="{ row }">
                <Link
                    :href="route('finance.bills.show', Number(row.bill_id))"
                    class="text-brand-700 hover:underline"
                >
                    {{ row.bill_no }}
                </Link>
            </template>
        </DataTable>
        <Card v-if="Object.keys(data.totals).length || data.notes.length">
            <dl class="grid gap-2 text-sm sm:grid-cols-3">
                <div
                    v-for="(v, k) in data.totals"
                    :key="k"
                >
                    <dt class="text-surface-500">
                        {{ String(k).replace(/_/g, ' ') }}
                    </dt>
                    <dd class="text-lg font-semibold">
                        <Amount :value="v" />
                    </dd>
                </div>
            </dl>
            <p
                v-if="data.balanced !== undefined"
                class="mt-2 text-sm"
                :class="data.balanced ? 'text-emerald-700' : 'text-red-700'"
            >
                {{ data.balanced ? 'Debits equal credits.' : 'Out of balance!' }}
            </p>
            <ul class="mt-2 list-inside list-disc text-xs text-surface-500">
                <li
                    v-for="n in data.notes"
                    :key="n"
                >
                    {{ n }}
                </li>
            </ul>
        </Card>
    </AppLayout>
</template>
