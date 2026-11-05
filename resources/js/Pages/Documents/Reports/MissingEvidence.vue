<script setup lang="ts">
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/Feedback/EmptyState.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportNav from './Nav.vue';

interface Row { rule_id: number; entity_type: string; link_type: string; description: string; status: string; missing: { entity_id: number; label: string }[] }
defineProps<{ rows: Row[] }>();
</script>

<template>
    <AppLayout
        title="Missing required evidence"
        :breadcrumbs="[{ label: 'Documents', href: route('documents.index') }, { label: 'Reports' }, { label: 'Missing evidence' }]"
    >
        <ReportNav />
        <Card
            v-for="r in rows"
            :key="r.rule_id"
            :title="r.description"
            :description="`${r.entity_type} needs a “${r.link_type}” document`"
        >
            <template #actions>
                <StatusChip
                    :status="r.status === 'pending_module' ? 'draft' : r.status"
                    :label="r.status === 'pending_module' ? 'Module not installed yet' : undefined"
                />
            </template>
            <ul
                v-if="r.missing.length"
                class="divide-y divide-surface-100 text-sm"
            >
                <li
                    v-for="m in r.missing"
                    :key="m.entity_id"
                    class="py-1.5"
                >
                    {{ m.label }} <span class="text-surface-400">#{{ m.entity_id }}</span>
                </li>
            </ul>
            <p
                v-else
                class="text-sm text-surface-500"
            >
                {{ r.status === 'pending_module' ? 'This rule applies once the module that owns these records is installed.' : 'Nothing missing among the records you can see.' }}
            </p>
        </Card>
        <EmptyState
            v-if="rows.length === 0"
            title="No evidence rules"
            message="Add required-evidence rules to track missing documents."
        />
    </AppLayout>
</template>
