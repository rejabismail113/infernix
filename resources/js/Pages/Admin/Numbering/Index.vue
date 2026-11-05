<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

defineProps<{ sequences: { id: number; document_type: string; fiscal_year: number; prefix: string; format: string; next_number: number; padding: number; preview: string }[]; can: { edit: boolean } }>();
const open = ref(false);
const form = useForm({ document_type: '', fiscal_year: new Date().getFullYear(), prefix: '', format: '{prefix}-{year}-{seq}', padding: 4, next_number: 1 });
const columns: TableColumn[] = [
    { key: 'document_type', label: 'Document type', sortable: true }, { key: 'fiscal_year', label: 'FY', sortable: true, numeric: true },
    { key: 'prefix', label: 'Prefix' }, { key: 'format', label: 'Format', hidden: true }, { key: 'next_number', label: 'Next no.', numeric: true, sortable: true },
    { key: 'preview', label: 'Next document number' },
];
</script>

<template>
    <AppLayout
        title="Document numbering"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Numbering' }]"
    >
        <template #actions>
            <Button
                v-if="can.edit"
                @click="open = true"
            >
                Add sequence
            </Button>
        </template>
        <DataTable
            :columns="columns"
            :rows="sequences"
            :sort="{ key: 'document_type', dir: 'asc' }"
            prefs-key="admin.numbering"
        >
            <template #cell-preview="{ row }">
                <span class="font-mono text-brand-700">{{ row.preview }}</span>
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Numbering sequence"
            size="md"
            @close="open = false"
        >
            <form
                id="seq-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="form.post(route('admin.numbering.store'), { onSuccess: () => { form.reset(); open = false; } })"
            >
                <FormField
                    id="document_type"
                    label="Type (UPPER_CASE)"
                    :error="form.errors.document_type"
                >
                    <input
                        id="document_type"
                        v-model="form.document_type"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="fiscal_year"
                    label="Fiscal year"
                >
                    <input
                        id="fiscal_year"
                        v-model.number="form.fiscal_year"
                        type="number"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="prefix"
                    label="Prefix"
                    :error="form.errors.prefix"
                >
                    <input
                        id="prefix"
                        v-model="form.prefix"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="padding"
                    label="Digits"
                >
                    <input
                        id="padding"
                        v-model.number="form.padding"
                        type="number"
                        min="1"
                        max="10"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="format"
                    label="Format"
                    :error="form.errors.format"
                    class="col-span-2"
                >
                    <input
                        id="format"
                        v-model="form.format"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2 font-mono"
                    >
                </FormField>
                <FormField
                    id="next_number"
                    label="Next number (can only move forward)"
                    class="col-span-2"
                >
                    <input
                        id="next_number"
                        v-model.number="form.next_number"
                        type="number"
                        min="1"
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
                    form="seq-form"
                    :disabled="form.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
