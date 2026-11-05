<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ rows: Record<string, unknown>[]; can: { export: boolean } }>();
</script>

<template>
    <AppLayout>
        <Head title="Policy history" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Policy history
            </h1>
        </template>
        <a
            v-if="can.export"
            :href="route('admin.reports.policy-history.export')"
            class="mb-4 inline-block rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
        >Export CSV (audited)</a>
        <SimpleTable
            :columns="[{ key: 'kind', label: 'Kind' }, { key: 'subject', label: 'Subject' }, { key: 'detail', label: 'Detail' }, { key: 'version', label: 'Ver.' }, { key: 'effective_from', label: 'From' }, { key: 'effective_to', label: 'To' }, { key: 'changed_by', label: 'By' }, { key: 'reason', label: 'Reason' }]"
            :rows="rows"
        />
    </AppLayout>
</template>
