<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ rules: { id: number; event: string; target_roles: string[] | null; channel: string; lead_days: number; active: boolean; notes: string | null }[]; roles: string[]; can: { edit: boolean } }>();
const form = useForm({ event: '', target_roles: ['OWN'] as string[], channel: 'both', lead_days: 0, active: true, notes: '' });
</script>

<template>
    <AppLayout>
        <Head title="Notification rules" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Notification rules
            </h1>
        </template>
        <SimpleTable
            :columns="[{ key: 'event', label: 'Event' }, { key: 'target_roles', label: 'Roles' }, { key: 'channel', label: 'Channel' }, { key: 'lead_days', label: 'Lead days' }, { key: 'active', label: 'Active' }, { key: 'notes', label: 'Notes' }]"
            :rows="rules"
        >
            <template #cell-active="{ row }">
                {{ row.active ? 'yes' : 'no' }}
            </template>
        </SimpleTable>
        <form
            v-if="can.edit"
            class="mt-6 grid max-w-4xl grid-cols-2 gap-3 text-sm md:grid-cols-6"
            @submit.prevent="form.post(route('admin.notification-rules.store'), { onSuccess: () => form.reset() })"
        >
            <input
                v-model="form.event"
                required
                placeholder="EventName"
                aria-label="Event"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <select
                v-model="form.target_roles"
                multiple
                aria-label="Roles"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="r in roles"
                    :key="r"
                >
                    {{ r }}
                </option>
            </select>
            <select
                v-model="form.channel"
                aria-label="Channel"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="c in ['email', 'in_app', 'both']"
                    :key="c"
                >
                    {{ c }}
                </option>
            </select>
            <input
                v-model.number="form.lead_days"
                type="number"
                min="0"
                aria-label="Lead days"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="form.notes"
                placeholder="notes"
                aria-label="Notes"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-brand-600 px-3 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Add rule
            </button>
            <p
                v-if="Object.keys(form.errors).length"
                class="col-span-full text-red-600"
            >
                {{ Object.values(form.errors).join(' ') }}
            </p>
        </form>
    </AppLayout>
</template>
