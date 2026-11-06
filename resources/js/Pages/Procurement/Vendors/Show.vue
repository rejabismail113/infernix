<script setup lang="ts">
/** Vendor profile (M10-01): registration/tax, contacts, contract documents, masked bank reference, spending, subscriptions. */
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { blanksToNull, setField } from '@/lib/forms';
import type { LinkedDoc } from '@/types/finance';

interface Vendor { id: number; name: string; registration_no: string | null; tax_no: string | null; bank_reference: string | null; status: string; email: string | null; phone: string | null; category: string | null; payment_terms_days: number; contact_person: string | null; address: string | null; notes: string | null }
const props = defineProps<{
    vendor: Vendor; documents: LinkedDoc[]; spending: { bills: number; billed: string; paid: string; outstanding: string } | null;
    subscriptions: { id: number; product: string; renewal_date: string; price: string; billing_cycle: string; status: string }[]; can: { edit: boolean };
}>();
const v = props.vendor;
const form = useForm({ name: v.name, registration_no: v.registration_no ?? '', tax_no: v.tax_no ?? '', email: v.email ?? '', phone: v.phone ?? '', contact_person: v.contact_person ?? '', category: v.category ?? '',
    payment_terms_days: String(v.payment_terms_days), address: v.address ?? '', bank_reference: '', notes: v.notes ?? '', status: v.status });
const save = () => form.transform((d) => blanksToNull(d, ['payment_terms_days'])).put(route('procurement.vendors.update', v.id), { preserveScroll: true, onSuccess: () => form.reset('bank_reference') });
</script>

<template>
    <AppLayout
        :title="vendor.name"
        :breadcrumbs="[{ label: 'Procurement' }, { label: 'Vendors', href: route('procurement.vendors.index') }, { label: vendor.name }]"
        :status="vendor.status"
    >
        <div class="grid gap-4 lg:grid-cols-3">
            <Card
                title="Vendor details"
                class="lg:col-span-2"
            >
                <form
                    class="space-y-3"
                    @submit.prevent="save"
                >
                    <SchemaFields
                        :fields="[{ key: 'name', label: 'Name', type: 'text', span: 2 }, { key: 'registration_no', label: 'SSM / registration no.', type: 'text' }, { key: 'tax_no', label: 'Tax / SST no.', type: 'text' },
                                  { key: 'contact_person', label: 'Contact person', type: 'text' }, { key: 'email', label: 'Email', type: 'email' }, { key: 'phone', label: 'Phone', type: 'text' }, { key: 'category', label: 'Category', type: 'text' },
                                  { key: 'payment_terms_days', label: 'Payment terms (days)', type: 'number' }, { key: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'active' }, { value: 'inactive', label: 'inactive' }] },
                                  { key: 'bank_reference', label: `Bank account — currently ${vendor.bank_reference ?? 'none'} (leave blank to keep)`, type: 'text', span: 2 },
                                  { key: 'address', label: 'Address', type: 'textarea' }, { key: 'notes', label: 'Notes', type: 'textarea' }]"
                        :values="form"
                        :errors="form.errors"
                        :disabled="!can.edit"
                        id-prefix="vs"
                        @set="(k, val) => setField(form, k, val)"
                    />
                    <Button
                        v-if="can.edit"
                        type="submit"
                        :disabled="form.processing"
                    >
                        Save
                    </Button>
                </form>
            </Card>
            <div class="space-y-4">
                <Card
                    v-if="spending"
                    title="Spending (approved bills)"
                >
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt>Bills</dt><dd>{{ spending.bills }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Billed</dt><dd><Amount :value="spending.billed" /></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Paid</dt><dd><Amount :value="spending.paid" /></dd>
                        </div>
                        <div class="flex justify-between font-medium">
                            <dt>Outstanding</dt><dd><Amount :value="spending.outstanding" /></dd>
                        </div>
                    </dl>
                </Card>
                <Card
                    v-if="subscriptions.length"
                    title="Subscriptions"
                >
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="s in subscriptions"
                            :key="s.id"
                            class="flex justify-between gap-2"
                        >
                            <span>{{ s.product }} <span class="text-surface-500">renews {{ s.renewal_date }}</span></span>
                            <StatusChip :status="s.status" />
                        </li>
                    </ul>
                </Card>
                <Card title="Contracts & registration documents">
                    <EvidencePanel
                        entity-type="vendor"
                        :entity-id="vendor.id"
                        link-type="contract"
                        :documents="documents"
                        :can-attach="can.edit"
                    />
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
