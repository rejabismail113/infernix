<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatBytes } from '@/lib/format';
import type { TableColumn } from '@/types/table';
import ReportNav from './Nav.vue';

defineProps<{ rows: Record<string, unknown>[]; can: { export: boolean } }>();
const columns: TableColumn[] = [
    { key: 'id', label: '#', numeric: true, sortable: true }, { key: 'title', label: 'Title', sortable: true, hideable: false },
    { key: 'classification', label: 'Classification', sortable: true }, { key: 'owner', label: 'Owner', sortable: true },
    { key: 'versions', label: 'Versions', numeric: true, sortable: true }, { key: 'links', label: 'Links', numeric: true, sortable: true },
    { key: 'file_name', label: 'Current file' }, { key: 'size_bytes', label: 'Size', numeric: true, sortable: true },
    { key: 'retention_until', label: 'Retain until', sortable: true }, { key: 'flags', label: 'Flags' },
    { key: 'content_hash', label: 'SHA-256', hidden: true }, { key: 'uploaded_at', label: 'Uploaded', sortable: true, hidden: true },
];
</script>

<template>
    <AppLayout
        title="Document inventory"
        :breadcrumbs="[{ label: 'Documents', href: route('documents.index') }, { label: 'Reports' }, { label: 'Inventory' }]"
    >
        <ReportNav />
        <DataTable
            :columns="columns"
            :rows="rows"
            :export-url="can.export ? route('documents.reports.inventory.export') : null"
            prefs-key="documents.inventory"
        >
            <template #cell-title="{ row }">
                <Link
                    :href="route('documents.show', Number(row.id))"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.title }}
                </Link>
            </template>
            <template #cell-classification="{ row }">
                <StatusChip :status="String(row.classification)" />
            </template>
            <template #cell-size_bytes="{ row }">
                {{ formatBytes(row.size_bytes as number | null) }}
            </template>
            <template #cell-flags="{ row }">
                <span class="flex gap-1">
                    <StatusChip
                        v-if="row.legal_hold"
                        status="legal_hold"
                    />
                    <StatusChip
                        v-if="row.frozen"
                        status="frozen"
                    />
                    <StatusChip
                        v-if="row.archived_at"
                        status="archived"
                    />
                </span>
            </template>
            <template #cell-content_hash="{ row }">
                <span class="font-mono text-xs">{{ String(row.content_hash ?? '').slice(0, 12) }}</span>
            </template>
        </DataTable>
    </AppLayout>
</template>
