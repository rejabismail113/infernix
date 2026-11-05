<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

defineProps<{ rows: Record<string, unknown>[]; can: { export: boolean } }>();
const columns: TableColumn[] = [
    { key: 'kind', label: 'Kind', sortable: true }, { key: 'subject', label: 'Subject', sortable: true }, { key: 'detail', label: 'Detail' },
    { key: 'version', label: 'Ver.', numeric: true }, { key: 'effective_from', label: 'From', sortable: true }, { key: 'effective_to', label: 'To' },
    { key: 'changed_by', label: 'By', hidden: true }, { key: 'reason', label: 'Reason' },
];
</script>

<template>
    <AppLayout
        title="Policy history"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Access reports', href: route('admin.reports.user-access') }, { label: 'Policy history' }]"
    >
        <template #actions>
            <Link
                :href="route('admin.reports.user-access')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                ← User access
            </Link>
        </template>
        <DataTable
            :columns="columns"
            :rows="rows"
            :export-url="can.export ? route('admin.reports.policy-history.export') : null"
            prefs-key="admin.policy-history"
        >
            <template #cell-kind="{ row }">
                <StatusChip
                    :status="String(row.kind)"
                    :tone="row.kind === 'audit' ? 'warning' : row.kind === 'delegation' ? 'info' : 'neutral'"
                />
            </template>
        </DataTable>
    </AppLayout>
</template>
