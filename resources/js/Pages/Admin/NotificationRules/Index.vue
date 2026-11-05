<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

defineProps<{ rules: { id: number; event: string; target_roles: string[] | null; channel: string; lead_days: number; active: boolean; notes: string | null }[]; roles: string[]; can: { edit: boolean } }>();
const open = ref(false);
const form = useForm({ event: '', target_roles: ['OWN'] as string[], channel: 'both', lead_days: 0, active: true, notes: '' });
const columns: TableColumn[] = [
    { key: 'event', label: 'Event', sortable: true }, { key: 'target_roles', label: 'Notify roles' }, { key: 'channel', label: 'Channel', sortable: true },
    { key: 'lead_days', label: 'Lead days', numeric: true, sortable: true }, { key: 'active', label: 'Status' }, { key: 'notes', label: 'Notes', hidden: true },
];
</script>

<template>
    <AppLayout
        title="Notification rules"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Notification rules' }]"
    >
        <template #actions>
            <Button
                v-if="can.edit"
                @click="open = true"
            >
                Add rule
            </Button>
        </template>
        <DataTable
            :columns="columns"
            :rows="rules"
            :sort="{ key: 'event', dir: 'asc' }"
            prefs-key="admin.notification-rules"
        >
            <template #cell-active="{ row }">
                <StatusChip :status="row.active ? 'active' : 'inactive'" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Notification rule"
            size="md"
            @close="open = false"
        >
            <form
                id="rule-form"
                class="grid gap-3"
                @submit.prevent="form.post(route('admin.notification-rules.store'), { onSuccess: () => { form.reset(); open = false; } })"
            >
                <FormField
                    id="event"
                    label="Event (e.g. InvoiceOverdue)"
                    :error="form.errors.event"
                >
                    <input
                        id="event"
                        v-model="form.event"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <fieldset>
                    <legend class="mb-1 text-sm font-medium">
                        Notify roles
                    </legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="r in roles"
                            :key="r"
                            class="flex items-center gap-1 text-sm"
                        ><input
                            v-model="form.target_roles"
                            type="checkbox"
                            :value="r"
                        > {{ r }}</label>
                    </div>
                </fieldset>
                <div class="grid grid-cols-2 gap-3">
                    <FormField
                        id="channel"
                        label="Channel"
                    >
                        <select
                            id="channel"
                            v-model="form.channel"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                            <option value="both">
                                Email + in-app
                            </option>
                            <option value="email">
                                Email
                            </option>
                            <option value="in_app">
                                In-app
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="lead_days"
                        label="Lead days"
                        :error="form.errors.lead_days"
                    >
                        <input
                            id="lead_days"
                            v-model.number="form.lead_days"
                            type="number"
                            min="0"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                </div>
                <FormField
                    id="notes"
                    label="Notes"
                >
                    <input
                        id="notes"
                        v-model="form.notes"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="rule-form"
                    :disabled="form.processing"
                >
                    Save rule
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
