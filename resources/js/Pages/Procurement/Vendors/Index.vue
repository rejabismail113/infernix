<script setup lang="ts">
/** Vendor master (M10-01). Bank references are always shown masked. */
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { blanksToNull, setField } from '@/lib/forms';
import type { FieldDef } from '@/types/operations';

export interface VendorRow { id: number; name: string; registration_no: string | null; tax_no: string | null; bank_reference: string | null; status: string; email: string | null; phone: string | null; category: string | null; payment_terms_days: number; contact_person: string | null }
defineProps<{ vendors: VendorRow[]; can: { create: boolean } }>();
const open = ref(false);
const form = useForm({ name: '', registration_no: '', tax_no: '', email: '', phone: '', contact_person: '', category: '', payment_terms_days: '30', address: '', bank_reference: '', notes: '' });
const fields: FieldDef[] = [
    { key: 'name', label: 'Vendor name', type: 'text', required: true, span: 2 }, { key: 'registration_no', label: 'SSM / registration no.', type: 'text' }, { key: 'tax_no', label: 'Tax / SST no.', type: 'text' },
    { key: 'contact_person', label: 'Contact person', type: 'text' }, { key: 'email', label: 'Email', type: 'email' }, { key: 'phone', label: 'Phone', type: 'text' }, { key: 'category', label: 'Category', type: 'text' },
    { key: 'payment_terms_days', label: 'Payment terms (days)', type: 'number' }, { key: 'bank_reference', label: 'Bank account (stored encrypted, shown masked)', type: 'text' },
    { key: 'address', label: 'Address', type: 'textarea' }, { key: 'notes', label: 'Notes', type: 'textarea' },
];
const save = () => form.transform((d) => blanksToNull(d, ['payment_terms_days'])).post(route('procurement.vendors.store'), { onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout
        title="Vendors"
        :breadcrumbs="[{ label: 'Procurement' }, { label: 'Vendors' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'procurement.vendor-spending')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Vendor spending
            </Link>
            <Button
                v-if="can.create"
                data-test="add-vendor"
                @click="open = true"
            >
                Add vendor
            </Button>
        </template>
        <DataTable
            :columns="[{ key: 'name', label: 'Vendor', sortable: true, hideable: false }, { key: 'category', label: 'Category', sortable: true }, { key: 'registration_no', label: 'SSM / reg. no.' }, { key: 'tax_no', label: 'Tax no.' }, { key: 'email', label: 'Email' }, { key: 'phone', label: 'Phone' }, { key: 'bank_reference', label: 'Bank (masked)' }, { key: 'status', label: 'Status' }]"
            :rows="vendors"
            prefs-key="vendors"
            empty-text="No vendors yet."
        >
            <template #cell-name="{ row }">
                <Link
                    :href="route('procurement.vendors.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.name }}
                </Link>
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Add vendor"
            @close="open = false"
        >
            <form
                id="vendor-form"
                @submit.prevent="save"
            >
                <SchemaFields
                    :fields="fields"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="v"
                    @set="(k, v) => setField(form, k, v)"
                />
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="vendor-form"
                    :disabled="form.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
