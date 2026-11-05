<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

interface Acc { id: number; code: string; name: string; type: string; subtype: string | null; system_key: string | null; is_postable: boolean; active: boolean; tax_treatment_default: string; notes: string | null }
defineProps<{ accounts: Acc[]; can: { create: boolean } }>();
const open = ref(false);
const form = useForm({ code: '', name: '', type: 'expense', subtype: '', tax_treatment_default: 'pending_review' });
const columns: TableColumn[] = [
    { key: 'code', label: 'Code', sortable: true, hideable: false }, { key: 'name', label: 'Account', sortable: true }, { key: 'type', label: 'Type', sortable: true },
    { key: 'system_key', label: 'Used by posting rule' }, { key: 'tax_treatment_default', label: 'Default tax treatment' }, { key: 'status', label: 'Status' }, { key: 'notes', label: 'Notes', hidden: true },
];
function toggle(a: Acc) {
    router.put(route('finance.accounts.update', a.id), { name: a.name, active: !a.active, notes: a.notes }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout
        title="Chart of accounts"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Chart of accounts' }]"
    >
        <template #chips>
            <StatusChip
                status="pending"
                label="PLACEHOLDER — pending accountant approval"
            />
        </template>
        <template #actions>
            <Button
                v-if="can.create"
                @click="open = true"
            >
                Add account
            </Button>
        </template>
        <DataTable
            :columns="columns"
            :rows="accounts"
            prefs-key="finance.accounts"
        >
            <template #cell-name="{ row }">
                <span :class="row.is_postable ? '' : 'font-semibold uppercase text-surface-500'">{{ row.name }}</span>
            </template>
            <template #cell-system_key="{ row }">
                <span class="font-mono text-xs text-surface-500">{{ row.system_key ?? '' }}</span>
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.active ? 'active' : 'inactive'" />
            </template>
            <template #actions="{ row }">
                <button
                    v-if="can.create && row.is_postable && !row.system_key"
                    type="button"
                    class="text-sm text-brand-700 hover:underline"
                    @click="toggle(row)"
                >
                    {{ row.active ? 'Deactivate' : 'Activate' }}
                </button>
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Add account"
            size="md"
            @close="open = false"
        >
            <form
                id="acc-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="form.post(route('finance.accounts.store'), { onSuccess: () => { form.reset(); open = false; } })"
            >
                <FormField
                    id="code"
                    label="Code"
                    :error="form.errors.code"
                >
                    <input
                        id="code"
                        v-model="form.code"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="type"
                    label="Type"
                >
                    <select
                        id="type"
                        v-model="form.type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="t in ['asset', 'liability', 'equity', 'income', 'expense']"
                            :key="t"
                        >
                            {{ t }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="name"
                    label="Name"
                    :error="form.errors.name"
                    class="col-span-2"
                >
                    <input
                        id="name"
                        v-model="form.name"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="tax"
                    label="Default tax treatment"
                    class="col-span-2"
                >
                    <select
                        id="tax"
                        v-model="form.tax_treatment_default"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="pending_review">
                            Pending accountant review
                        </option>
                        <option value="deductible">
                            Deductible
                        </option>
                        <option value="non_deductible">
                            Non-deductible
                        </option>
                        <option value="capital">
                            Capital
                        </option>
                    </select>
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
                    form="acc-form"
                    :disabled="form.processing"
                >
                    Add
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
