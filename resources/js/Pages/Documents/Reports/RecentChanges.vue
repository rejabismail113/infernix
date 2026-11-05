<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportNav from './Nav.vue';

defineProps<{ rows: Record<string, unknown>[]; days: number }>();
</script>

<template>
    <AppLayout>
        <Head title="Recent document changes" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Recent changes (last {{ days }} days)
            </h1>
        </template>
        <ReportNav />
        <SimpleTable
            :columns="[{ key: 'occurred_at', label: 'When' }, { key: 'document_id', label: 'Document #' }, { key: 'action', label: 'Action' }, { key: 'actor', label: 'By' }, { key: 'reason', label: 'Reason' }]"
            :rows="rows"
        />
    </AppLayout>
</template>
