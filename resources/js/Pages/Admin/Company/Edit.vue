<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Company { id: number; name: string; ssm_no: string | null; address: Record<string, string> | null; phone: string | null; email: string | null; signatory_name: string | null; signatory_title: string | null; base_currency: string; timezone: string }
const props = defineProps<{ company: Company; bankAccounts: { id: number; bank_name: string; account_no_masked: string; account_name: string; is_default: boolean; active: boolean }[]; can: { edit: boolean } }>();
const addressKeys = ['line1', 'city', 'postcode', 'state', 'country'] as const;
const form = useForm({ ...props.company, address: { line1: '', city: '', postcode: '', state: '', country: 'Malaysia', ...(props.company.address ?? {}) } as Record<(typeof addressKeys)[number], string> });
const bank = useForm({ bank_name: '', account_no: '', account_name: '', is_default: false });
const fields: { key: keyof Omit<Company, 'id' | 'address' | 'base_currency' | 'timezone'>; label: string }[] = [
    { key: 'name', label: 'Company name' }, { key: 'ssm_no', label: 'SSM no.' }, { key: 'phone', label: 'Phone' }, { key: 'email', label: 'Email' },
    { key: 'signatory_name', label: 'Authorised signatory' }, { key: 'signatory_title', label: 'Signatory title' },
];
</script>

<template>
    <AppLayout
        title="Company profile"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Company' }]"
    >
        <template #chips>
            <StatusChip
                :status="company.base_currency"
                tone="info"
                :label="company.base_currency"
            />
            <StatusChip
                :status="company.timezone"
                tone="neutral"
                :label="company.timezone"
            />
        </template>
        <Card
            title="Issuer details"
            description="Used on quotations, invoices, receipts and vouchers. Snapshotted when a document is issued."
        >
            <form
                class="grid grid-cols-1 gap-3 md:grid-cols-2"
                @submit.prevent="form.put(route('admin.company.update'))"
            >
                <FormField
                    v-for="f in fields"
                    :id="f.key"
                    :key="f.key"
                    :label="f.label"
                    :error="form.errors[f.key]"
                >
                    <input
                        :id="f.key"
                        v-model="form[f.key]"
                        :disabled="!can.edit"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 disabled:bg-surface-50"
                    >
                </FormField>
                <FormField
                    v-for="k in addressKeys"
                    :id="'addr-' + k"
                    :key="k"
                    :label="'Address — ' + k"
                >
                    <input
                        :id="'addr-' + k"
                        v-model="form.address[k]"
                        :disabled="!can.edit"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 disabled:bg-surface-50"
                    >
                </FormField>
                <div
                    v-if="can.edit"
                    class="md:col-span-2"
                >
                    <Button
                        type="submit"
                        :disabled="form.processing"
                    >
                        Save
                    </Button>
                </div>
            </form>
        </Card>

        <Card
            title="Bank accounts"
            description="Account numbers are encrypted and only shown masked."
        >
            <DataTable
                :columns="[{ key: 'bank_name', label: 'Bank' }, { key: 'account_no_masked', label: 'Account no.' }, { key: 'account_name', label: 'Account name' }, { key: 'is_default', label: 'Default' }]"
                :rows="bankAccounts"
                empty-text="No bank accounts yet."
            >
                <template #cell-is_default="{ row }">
                    <StatusChip
                        v-if="row.is_default"
                        status="active"
                        label="Default"
                    />
                </template>
            </DataTable>
            <form
                v-if="can.edit"
                class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-5"
                @submit.prevent="bank.post(route('admin.company.bank-accounts.store'), { onSuccess: () => bank.reset() })"
            >
                <input
                    v-model="bank.bank_name"
                    required
                    placeholder="Bank"
                    aria-label="Bank"
                    class="rounded-md border border-surface-300 px-3 py-2"
                >
                <input
                    v-model="bank.account_no"
                    required
                    placeholder="Account no."
                    aria-label="Account number"
                    class="rounded-md border border-surface-300 px-3 py-2"
                >
                <input
                    v-model="bank.account_name"
                    required
                    placeholder="Account name"
                    aria-label="Account name"
                    class="rounded-md border border-surface-300 px-3 py-2"
                >
                <label class="flex items-center gap-2"><input
                    v-model="bank.is_default"
                    type="checkbox"
                > Default account</label>
                <Button
                    type="submit"
                    :disabled="bank.processing"
                >
                    Add account
                </Button>
            </form>
        </Card>
    </AppLayout>
</template>
