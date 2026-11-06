<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ProjectRow { id: number; code: string; name: string; client: string; engagement_type: string; contract_value: string; status_delivery: string; status_payment: string; source_quotation_id: number | null }
defineProps<{ projects: ProjectRow[] }>();
</script>

<template>
    <AppLayout
        title="Projects"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Projects' }]"
    >
        <p class="text-sm text-surface-600">
            Projects are created from accepted quotations (Quotation → “Create project”). Milestones, team and training sessions arrive with the Projects module.
        </p>
        <DataTable
            :columns="[{ key: 'code', label: 'Code', hideable: false }, { key: 'name', label: 'Project' }, { key: 'client', label: 'Client' }, { key: 'contract_value', label: 'Contract value', numeric: true }, { key: 'status_delivery', label: 'Delivery' }, { key: 'status_payment', label: 'Billing' }]"
            :rows="projects"
            prefs-key="projects.list"
            empty-text="No projects yet."
        >
            <template #cell-code="{ row }">
                <Link
                    :href="route('projects.projects.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.code }}
                </Link>
            </template>
            <template #cell-contract_value="{ row }">
                <Amount :value="row.contract_value" />
            </template>
            <template #cell-status_delivery="{ row }">
                <StatusChip :status="row.status_delivery" />
            </template>
            <template #cell-status_payment="{ row }">
                <StatusChip
                    :status="row.status_payment === 'paid' ? 'paid' : row.status_payment === 'unbilled' ? 'draft' : 'pending'"
                    :label="row.status_payment.replace('_', ' ')"
                />
            </template>
        </DataTable>
    </AppLayout>
</template>
