<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ periods: { id: number; label: string; starts_on: string; ends_on: string; locked_at: string | null; current: boolean }[] }>();
</script>

<template>
    <AppLayout
        title="Period close"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Period close' }]"
    >
        <DataTable
            :columns="[{ key: 'label', label: 'Period' }, { key: 'range', label: 'Dates' }, { key: 'status', label: 'Status' }]"
            :rows="periods"
        >
            <template #cell-label="{ row }">
                <Link
                    :href="route('finance.periods.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.label }}
                </Link>
                <StatusChip
                    v-if="row.current"
                    status="info"
                    tone="info"
                    label="current"
                    class="ml-2"
                />
            </template>
            <template #cell-range="{ row }">
                {{ row.starts_on }} → {{ row.ends_on }}
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.locked_at ? 'locked' : 'open'" />
            </template>
        </DataTable>
    </AppLayout>
</template>
