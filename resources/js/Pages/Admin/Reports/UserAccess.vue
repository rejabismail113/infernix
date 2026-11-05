<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Row { id: number; name: string; email: string; roles: string[]; active: boolean; mfa_enabled: boolean; last_login_at: string | null; access_from: string | null; access_until: string | null; permissions: { resource: string; action: string; record_scope: string }[] }
defineProps<{ rows: Row[]; can: { export: boolean } }>();
</script>

<template>
    <AppLayout>
        <Head title="User access report" />
        <template #header>
            <h1 class="text-lg font-semibold">
                User access report
            </h1>
        </template>
        <a
            v-if="can.export"
            :href="route('admin.reports.user-access.export')"
            class="mb-4 inline-block rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
        >Export CSV (audited)</a>
        <div
            v-for="r in rows"
            :key="r.id"
            class="mb-3 rounded-lg border border-surface-200 bg-white p-3 text-sm"
        >
            <div class="font-semibold">
                {{ r.name }} <span class="font-normal text-surface-500">{{ r.email }}</span>
                <span class="ml-2 text-xs">{{ r.roles.join(', ') }} · {{ r.active ? 'active' : 'deactivated' }} · MFA {{ r.mfa_enabled ? 'on' : 'off' }} · last login {{ r.last_login_at ?? 'never' }}</span>
                <span
                    v-if="r.access_until"
                    class="ml-2 text-xs"
                >window {{ r.access_from?.slice(0, 10) }}→{{ r.access_until.slice(0, 10) }}</span>
            </div>
            <p class="mt-1 font-mono text-xs text-surface-600">
                <span
                    v-for="p in r.permissions"
                    :key="p.resource + p.action"
                    class="mr-2"
                >{{ p.resource }}.{{ p.action }}={{ p.record_scope }}</span>
            </p>
        </div>
    </AppLayout>
</template>
