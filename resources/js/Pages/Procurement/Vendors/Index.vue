<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ vendors: { id: number; name: string; registration_no: string | null; tax_no: string | null; bank_reference: string | null; status: string; email: string | null; phone: string | null }[]; can: { create: boolean } }>();
const open = ref(false);
const form = useForm({ name: '', registration_no: '', tax_no: '', email: '', phone: '', bank_reference: '' });
</script>

<template>
    <AppLayout
        title="Vendors"
        :breadcrumbs="[{ label: 'Procurement' }, { label: 'Vendors' }]"
    >
        <template #actions>
            <Button
                v-if="can.create"
                @click="open = true"
            >
                Add vendor
            </Button>
        </template>
        <DataTable
            :columns="[{ key: 'name', label: 'Vendor', sortable: true, hideable: false }, { key: 'registration_no', label: 'SSM / reg. no.' }, { key: 'tax_no', label: 'Tax no.' }, { key: 'email', label: 'Email' }, { key: 'phone', label: 'Phone' }, { key: 'bank_reference', label: 'Bank (masked)' }, { key: 'status', label: 'Status' }]"
            :rows="vendors"
            prefs-key="procurement.vendors"
        >
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Add vendor"
            size="md"
            @close="open = false"
        >
            <form
                id="vendor-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="form.post(route('procurement.vendors.store'), { onSuccess: () => { form.reset(); open = false; } })"
            >
                <FormField
                    id="v-name"
                    label="Name"
                    :error="form.errors.name"
                    class="col-span-2"
                >
                    <input
                        id="v-name"
                        v-model="form.name"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="v-reg"
                    label="SSM / reg. no."
                >
                    <input
                        id="v-reg"
                        v-model="form.registration_no"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="v-tax"
                    label="Tax no."
                >
                    <input
                        id="v-tax"
                        v-model="form.tax_no"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="v-email"
                    label="Email"
                    :error="form.errors.email"
                >
                    <input
                        id="v-email"
                        v-model="form.email"
                        type="email"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="v-phone"
                    label="Phone"
                >
                    <input
                        id="v-phone"
                        v-model="form.phone"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="v-bank"
                    label="Bank account (stored encrypted, shown masked)"
                    class="col-span-2"
                >
                    <input
                        id="v-bank"
                        v-model="form.bank_reference"
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
                    form="vendor-form"
                    :disabled="form.processing"
                >
                    Add vendor
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
