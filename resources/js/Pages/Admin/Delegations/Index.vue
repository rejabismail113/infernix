<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMyr } from '@/lib/money';
import type { TableColumn } from '@/types/table';

interface Row { id: number; delegator: string; delegate: string; entity_type: string; max_amount: string | null; valid_from: string; valid_to: string; reason: string; version: number; supersedes_id: number | null; revoked_at: string | null }
defineProps<{ delegations: Row[]; users: { id: number; name: string }[]; can: { create: boolean } }>();
const open = ref(false);
const form = useForm({ delegate_id: '', entity_type: 'claim', max_amount: '' as string | number | null, valid_from: '', valid_to: '', reason: '' });
const columns: TableColumn[] = [
    { key: 'delegator', label: 'Delegator', sortable: true }, { key: 'delegate', label: 'Delegate', sortable: true },
    { key: 'entity_type', label: 'Entity', sortable: true }, { key: 'max_amount', label: 'Max amount', numeric: true, sortable: true },
    { key: 'valid_from', label: 'From', sortable: true }, { key: 'valid_to', label: 'To', sortable: true },
    { key: 'version', label: 'Ver.', numeric: true }, { key: 'status', label: 'Status' }, { key: 'reason', label: 'Reason', hidden: true },
];
const statusOf = (r: Row) => (r.revoked_at ? 'cancelled' : new Date(r.valid_to) < new Date() ? 'expired' : 'active');
function revoke(id: number) {
    const reason = window.prompt('Reason for revocation (audited)');
    if (reason) router.post(route('admin.delegations.revoke', id), { reason });
}
</script>

<template>
    <AppLayout
        title="Approval delegations"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Delegations' }]"
    >
        <template #actions>
            <Button
                v-if="can.create"
                @click="open = true"
            >
                New delegation
            </Button>
        </template>
        <DataTable
            :columns="columns"
            :rows="delegations"
            prefs-key="admin.delegations"
        >
            <template #cell-max_amount="{ row }">
                {{ row.max_amount ? formatMyr(row.max_amount) : 'no limit' }}
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="statusOf(row)" />
            </template>
            <template #actions="{ row }">
                <button
                    v-if="!row.revoked_at"
                    type="button"
                    class="text-sm text-red-700 hover:underline"
                    @click="revoke(row.id)"
                >
                    Revoke
                </button>
            </template>
        </DataTable>

        <Modal
            :open="open"
            title="Delegate approval authority"
            size="md"
            @close="open = false"
        >
            <form
                id="delegation-form"
                class="grid gap-3"
                @submit.prevent="form.post(route('admin.delegations.store'), { onSuccess: () => { form.reset(); open = false; } })"
            >
                <FormField
                    id="delegate"
                    label="Delegate"
                    :error="form.errors.delegate_id"
                >
                    <select
                        id="delegate"
                        v-model="form.delegate_id"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            value=""
                            disabled
                        >
                            Choose…
                        </option>
                        <option
                            v-for="u in users"
                            :key="u.id"
                            :value="u.id"
                        >
                            {{ u.name }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="entity_type"
                    label="Approvals of"
                >
                    <select
                        id="entity_type"
                        v-model="form.entity_type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="t in ['claim', 'payment', 'invoice', 'bill', 'purchase_request', 'credit_note', 'asset_disposal']"
                            :key="t"
                        >
                            {{ t }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="max_amount"
                    label="Maximum amount (blank = no limit)"
                    :error="form.errors.max_amount"
                >
                    <MoneyInput
                        id="max_amount"
                        v-model="form.max_amount"
                    />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField
                        id="valid_from"
                        label="From"
                        :error="form.errors.valid_from"
                    >
                        <input
                            id="valid_from"
                            v-model="form.valid_from"
                            type="date"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="valid_to"
                        label="To"
                        :error="form.errors.valid_to"
                    >
                        <input
                            id="valid_to"
                            v-model="form.valid_to"
                            type="date"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                </div>
                <FormField
                    id="reason"
                    label="Reason (audited)"
                    :error="form.errors.reason"
                >
                    <input
                        id="reason"
                        v-model="form.reason"
                        required
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
                    form="delegation-form"
                    :disabled="form.processing"
                >
                    Create delegation
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
