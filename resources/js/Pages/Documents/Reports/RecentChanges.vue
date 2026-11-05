<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';
import ReportNav from './Nav.vue';

defineProps<{ rows: Record<string, unknown>[]; days: number }>();
const columns: TableColumn[] = [
    { key: 'occurred_at', label: 'When', sortable: true }, { key: 'document_id', label: 'Document', sortable: true },
    { key: 'action', label: 'Action', sortable: true }, { key: 'actor', label: 'By', sortable: true }, { key: 'reason', label: 'Reason' },
];
</script>

<template>
    <AppLayout
        :title="`Recent changes · last ${days} days`"
        :breadcrumbs="[{ label: 'Documents', href: route('documents.index') }, { label: 'Reports' }, { label: 'Recent changes' }]"
    >
        <ReportNav />
        <DataTable
            :columns="columns"
            :rows="rows"
            prefs-key="documents.recent"
        >
            <template #cell-occurred_at="{ row }">
                <span class="font-mono text-xs">{{ String(row.occurred_at).replace('T', ' ').slice(0, 19) }}</span>
            </template>
            <template #cell-document_id="{ row }">
                <Link
                    v-if="row.action !== 'hard_delete'"
                    :href="route('documents.show', Number(row.document_id))"
                    class="text-brand-700 hover:underline"
                >
                    #{{ row.document_id }}
                </Link>
                <span v-else>#{{ row.document_id }}</span>
            </template>
            <template #cell-action="{ row }">
                <StatusChip
                    :status="String(row.action)"
                    :label="String(row.action)"
                    :tone="row.action === 'hard_delete' ? 'danger' : 'neutral'"
                />
            </template>
        </DataTable>
    </AppLayout>
</template>
