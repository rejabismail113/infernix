<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportNav from './Nav.vue';

defineProps<{ rows: Record<string, unknown>[]; can: { export: boolean } }>();
</script>

<template>
    <AppLayout>
        <Head title="Document inventory" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Document inventory
            </h1>
        </template>
        <ReportNav />
        <a
            v-if="can.export"
            :href="route('documents.reports.inventory.export')"
            class="mb-4 inline-block rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
        >Export CSV (audited)</a>
        <SimpleTable
            :columns="[{ key: 'id', label: '#' }, { key: 'title', label: 'Title' }, { key: 'classification', label: 'Class.' }, { key: 'owner', label: 'Owner' }, { key: 'versions', label: 'Versions' }, { key: 'links', label: 'Links' }, { key: 'file_name', label: 'Current file' }, { key: 'retention_until', label: 'Retain until' }, { key: 'legal_hold', label: 'Hold' }, { key: 'archived_at', label: 'Archived' }]"
            :rows="rows"
        >
            <template #cell-legal_hold="{ row }">
                {{ row.legal_hold ? 'yes' : '' }}
            </template>
        </SimpleTable>
    </AppLayout>
</template>
