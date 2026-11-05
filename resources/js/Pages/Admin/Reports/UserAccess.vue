<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

interface Row { id: number; name: string; email: string; roles: string[]; active: boolean; mfa_enabled: boolean; last_login_at: string | null; access_from: string | null; access_until: string | null; permissions: { resource: string; action: string; record_scope: string }[] }
defineProps<{ rows: Row[]; can: { export: boolean } }>();
const columns: TableColumn[] = [
    { key: 'name', label: 'User', sortable: true, hideable: false }, { key: 'roles', label: 'Roles' }, { key: 'active', label: 'Status' },
    { key: 'mfa_enabled', label: 'MFA' }, { key: 'last_login_at', label: 'Last login', sortable: true }, { key: 'permissions', label: 'Effective permissions' },
];
</script>

<template>
    <AppLayout
        title="User access report"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Access reports' }, { label: 'User access' }]"
    >
        <template #actions>
            <Link
                :href="route('admin.reports.policy-history')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Policy history →
            </Link>
        </template>
        <DataTable
            :columns="columns"
            :rows="rows"
            :export-url="can.export ? route('admin.reports.user-access.export') : null"
            prefs-key="admin.user-access"
        >
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span> <span class="block text-xs text-surface-500">{{ row.email }}</span>
            </template>
            <template #cell-active="{ row }">
                <StatusChip :status="row.active ? 'active' : 'deactivated'" />
            </template>
            <template #cell-mfa_enabled="{ row }">
                {{ row.mfa_enabled ? 'On' : 'Off' }}
            </template>
            <template #cell-permissions="{ row }">
                <p class="max-w-xl font-mono text-xs text-surface-600">
                    <span
                        v-for="p in row.permissions"
                        :key="p.resource + p.action"
                        class="mr-2 inline-block"
                    >{{ p.resource }}.{{ p.action }}={{ p.record_scope }}</span>
                </p>
            </template>
        </DataTable>
    </AppLayout>
</template>
