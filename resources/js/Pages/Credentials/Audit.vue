<script setup lang="ts">
/** Credential access audit (M13-04): reveals, grants, revokes, requests and rotations — never any secret value. */
import { Link } from '@inertiajs/vue3';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

defineProps<{ events: { id: number; occurred_at: string; action: string; actor: string; credential: string; credential_id: number; reason: string | null; ip: string | null }[] }>();
const columns: TableColumn[] = [{ key: 'occurred_at', label: 'When', sortable: true }, { key: 'action', label: 'Action', sortable: true }, { key: 'actor', label: 'By', sortable: true },
    { key: 'credential', label: 'Credential', sortable: true }, { key: 'reason', label: 'Reason' }, { key: 'ip', label: 'IP' }];
</script>

<template>
    <AppLayout
        title="Credential access audit"
        :breadcrumbs="[{ label: 'Security' }, { label: 'Credentials', href: route('credentials.credentials.index') }, { label: 'Access audit' }]"
    >
        <DataTable
            :columns="columns"
            :rows="events"
            prefs-key="credential-audit"
            empty-text="No credential activity yet."
        >
            <template #cell-occurred_at="{ row }">
                {{ String(row.occurred_at).slice(0, 19).replace('T', ' ') }}
            </template>
            <template #cell-credential="{ row }">
                <Link
                    :href="route('credentials.credentials.show', row.credential_id)"
                    class="text-brand-700 hover:underline"
                >
                    {{ row.credential }}
                </Link>
            </template>
        </DataTable>
    </AppLayout>
</template>
