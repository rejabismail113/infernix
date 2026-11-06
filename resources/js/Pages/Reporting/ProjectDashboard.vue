<script setup lang="ts">
/** Project dashboard (M11-03): value, status, margin, due milestones and training pipeline (row-scoped). */
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

interface Row { id: number; code: string; name: string; client: string; engagement_type: string; status_delivery: string; status_payment: string; contract_value: string; net_revenue: string; direct_costs: string; contribution: string; margin_percent: string | null; overdue: number; next_milestone: { title: string; due_on: string } | null }
defineProps<{
    projects: Row[]; totals: { contract_value: string; net_revenue: string; direct_costs: string; contribution: string };
    milestones: { project_id: number; title: string; due_on: string; status: string; overdue: boolean }[];
    training: { session_date: string; course: string; project: string; client: string; status: string; participants: number }[];
}>();
const columns: TableColumn[] = [
    { key: 'code', label: 'Project', sortable: true }, { key: 'client', label: 'Client', sortable: true }, { key: 'status_delivery', label: 'Delivery' }, { key: 'status_payment', label: 'Payment' },
    { key: 'contract_value', label: 'Contract', numeric: true, sortable: true }, { key: 'net_revenue', label: 'Net revenue', numeric: true, sortable: true },
    { key: 'direct_costs', label: 'Direct costs', numeric: true }, { key: 'contribution', label: 'Contribution', numeric: true, sortable: true }, { key: 'margin_percent', label: 'Margin %', align: 'right', sortable: true },
    { key: 'next', label: 'Next milestone' },
];
</script>

<template>
    <AppLayout
        title="Project dashboard"
        :breadcrumbs="[{ label: 'Reports', href: route('reports.index') }, { label: 'Project dashboard' }]"
    >
        <div class="mb-5 grid gap-4 sm:grid-cols-4">
            <Card
                v-for="(label, key) in { contract_value: 'Contract value', net_revenue: 'Net revenue', direct_costs: 'Direct costs', contribution: 'Contribution' }"
                :key="key"
                :title="label"
            >
                <p class="text-xl font-semibold">
                    <Amount :value="totals[key]" />
                </p>
            </Card>
        </div>
        <DataTable
            :columns="columns"
            :rows="projects"
            prefs-key="project-dashboard"
            empty-text="No open projects you can see."
        >
            <template #cell-code="{ row }">
                <Link
                    :href="route('reports.projects.financials', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.code }}
                </Link>
                <span class="block text-xs text-surface-500">{{ row.name }}</span>
            </template>
            <template #cell-status_delivery="{ row }">
                <StatusChip :status="row.status_delivery" />
            </template>
            <template #cell-status_payment="{ row }">
                <StatusChip :status="row.status_payment" />
            </template>
            <template #cell-contract_value="{ row }">
                <Amount :value="row.contract_value" />
            </template>
            <template #cell-net_revenue="{ row }">
                <Amount :value="row.net_revenue" />
            </template>
            <template #cell-direct_costs="{ row }">
                <Amount :value="row.direct_costs" />
            </template>
            <template #cell-contribution="{ row }">
                <Amount :value="row.contribution" />
            </template>
            <template #cell-margin_percent="{ row }">
                {{ row.margin_percent === null ? '—' : `${row.margin_percent}%` }}
            </template>
            <template #cell-next="{ row }">
                <span v-if="row.next_milestone">{{ row.next_milestone.title }} · {{ row.next_milestone.due_on }}</span>
                <StatusChip
                    v-if="row.overdue"
                    status="rejected"
                    :label="`${row.overdue} overdue`"
                />
            </template>
        </DataTable>
        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <Card title="Milestones due in 30 days (and overdue)">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="(m, i) in milestones"
                        :key="i"
                        class="flex justify-between py-1.5"
                    >
                        <span>{{ projects.find((p) => p.id === m.project_id)?.code }} · {{ m.title }}</span>
                        <span :class="m.overdue ? 'text-red-700' : 'text-surface-500'">{{ m.due_on }}</span>
                    </li>
                    <li
                        v-if="!milestones.length"
                        class="py-2 text-surface-500"
                    >
                        Nothing due.
                    </li>
                </ul>
            </Card>
            <Card title="Training pipeline (next 90 days)">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="(t, i) in training"
                        :key="i"
                        class="flex justify-between py-1.5"
                    >
                        <span>{{ t.session_date }} · {{ t.course }} <span class="text-surface-500">({{ t.client }})</span></span>
                        <span>{{ t.participants }} participants · <StatusChip :status="t.status" /></span>
                    </li>
                    <li
                        v-if="!training.length"
                        class="py-2 text-surface-500"
                    >
                        No sessions scheduled.
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
