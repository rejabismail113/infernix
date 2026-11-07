<script setup lang="ts">
/**
 * Clients Management (M02-01): client master list and the Add / Edit Client form (owner spec 2026-11-07) —
 * auto Client ID, type, status, industry, registration / TIN / SST, e-Invoice buyer identification, address with
 * country + state dropdowns, and several contact persons (one primary). Same compact layout as the lead form.
 */
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Contact { id?: number | null; name: string; position: string; email: string; phone: string; is_primary: boolean }
interface ClientRow {
    id: number; client_code: string | null; name: string; client_type: string; client_type_other: string | null; type_label: string; industry: string | null;
    registration_no: string | null; tax_no: string | null; sst_no: string | null; einvoice_id_type: string | null; einvoice_id_no: string | null;
    email: string | null; phone: string | null; contact_person: string | null; address: Record<string, string> | null; address_line: string;
    payment_terms_days: number; status: string; projects: number;
    contacts: { id: number; name: string; position: string | null; email: string | null; phone: string | null; is_primary: boolean }[];
}
interface Option { value: string; label: string }
const props = defineProps<{
    clients: ClientRow[]; filters: Record<string, string | null>;
    options: { types: Option[]; industries: string[]; states: string[]; countries: string[]; idTypes: Option[] };
    can: { create: boolean; edit: boolean };
}>();

const f = reactive({ q: props.filters.q ?? '' });
const editing = ref<ClientRow | null>(null);
const open = ref(false);
const statusTone = (s: string) => (s === 'active' ? 'success' : s === 'prospect' ? 'info' : 'neutral');
const statusLabel = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const emptyContact = (primary = false): Contact => ({ id: null, name: '', position: '', email: '', phone: '', is_primary: primary });
const blank = () => ({
    name: '', client_type: '', client_type_other: '', status: 'active', industry: '', industry_other: '',
    registration_no: '', tax_no: '', sst_no: '', einvoice_id_type: '', einvoice_id_no: '', email: '', phone: '', payment_terms_days: 30 as number | string,
    address: { line1: '', line2: '', postcode: '', city: '', state: '', country: 'Malaysia' },
    country_other: '',
    contacts: [emptyContact(true)] as Contact[],
});
const form = useForm(blank());

const isMalaysia = computed(() => form.address.country === 'Malaysia');
function edit(c: ClientRow | null) {
    editing.value = c;
    const b = blank();
    if (c) {
        const industryKnown = !c.industry || props.options.industries.includes(c.industry);
        const country = c.address?.country ?? 'Malaysia';
        const countryKnown = props.options.countries.includes(country);
        Object.assign(b, {
            name: c.name, client_type: c.client_type, client_type_other: c.client_type_other ?? '', status: c.status,
            industry: industryKnown ? (c.industry ?? '') : 'Others', industry_other: industryKnown ? '' : (c.industry ?? ''),
            registration_no: c.registration_no ?? '', tax_no: c.tax_no ?? '', sst_no: c.sst_no ?? '', einvoice_id_type: c.einvoice_id_type ?? '',
            einvoice_id_no: c.einvoice_id_no ?? '', email: c.email ?? '', phone: c.phone ?? '', payment_terms_days: c.payment_terms_days,
            address: { ...b.address, ...(c.address ?? {}), country: countryKnown ? country : 'Others' },
            country_other: countryKnown ? '' : country,
            contacts: c.contacts.length
                ? c.contacts.map((k) => ({ id: k.id, name: k.name, position: k.position ?? '', email: k.email ?? '', phone: k.phone ?? '', is_primary: k.is_primary }))
                : [emptyContact(true)],
        });
    }
    form.defaults(b);
    form.reset();
    form.clearErrors();
    open.value = true;
}
function addContact() { form.contacts.push(emptyContact(form.contacts.length === 0)); }
function removeContact(i: number) {
    const wasPrimary = form.contacts[i]?.is_primary;
    form.contacts.splice(i, 1);
    if (wasPrimary && form.contacts[0]) form.contacts[0].is_primary = true;
}
function makePrimary(i: number) { form.contacts.forEach((c, j) => { c.is_primary = j === i; }); }

const errs = computed(() => form.errors as Record<string, string | undefined>);
function save() {
    const t = form.transform((d) => ({
        name: d.name, client_type: d.client_type, client_type_other: d.client_type === 'other' ? d.client_type_other : null, status: d.status,
        industry: d.industry === 'Others' ? (d.industry_other || null) : (d.industry || null),
        registration_no: d.registration_no || null, tax_no: d.tax_no || null, sst_no: d.sst_no || null,
        einvoice_id_type: d.einvoice_id_type || null,
        // Companies usually identify with their SSM no. on e-Invoices; use it when the ID number is left blank.
        einvoice_id_no: d.einvoice_id_no || (d.einvoice_id_type === 'brn' ? d.registration_no || null : null),
        email: d.email || null, phone: d.phone || null, payment_terms_days: d.payment_terms_days,
        address: { ...d.address, country: d.address.country === 'Others' ? d.country_other : d.address.country },
        contacts: d.contacts.filter((c) => c.name.trim() !== '').map((c) => ({ ...c, id: c.id ?? null })),
    }));
    const opts = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    if (editing.value) t.put(route('projects.clients.update', editing.value.id), opts);
    else t.post(route('projects.clients.store'), opts);
}
</script>

<template>
    <AppLayout
        title="Clients Management"
        subtitle="This module serves as a centralized database for managing Infernix's client information, including company details, registration numbers, tax information, contact persons, addresses, and payment terms. Client records are integrated with Sales Pipeline, Project Management, Quotations, Invoices, and Finance to ensure consistent information across all business operations."
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Clients Management' }]"
    >
        <template #actions>
            <Button
                v-if="can.create"
                pill
                data-test="add-client"
                @click="edit(null)"
            >
                <Icon
                    name="plus"
                    :size="18"
                />
                Add Client
            </Button>
        </template>
        <form
            class="flex gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('projects.clients.index'), { q: f.q || undefined }, { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Client ID, name, reg. no. or email"
                aria-label="Search"
                class="w-72"
            >
            <Button type="submit">
                Search
            </Button>
        </form>
        <DataTable
            :columns="[
                { key: 'client_code', label: 'Client ID', sortable: true },
                { key: 'name', label: 'Client', sortable: true, hideable: false },
                { key: 'type_label', label: 'Type', sortable: true },
                { key: 'registration_no', label: 'SSM / reg. no.' },
                { key: 'contact_person', label: 'Contact' },
                { key: 'email', label: 'Email' },
                { key: 'payment_terms_days', label: 'Terms (days)', numeric: true },
                { key: 'projects', label: 'Projects', numeric: true },
                { key: 'status', label: 'Status', sortable: true },
            ]"
            :rows="clients"
            prefs-key="projects.clients"
            empty-text="No clients yet."
        >
            <template #cell-client_code="{ row }">
                <span class="whitespace-nowrap font-medium tabular-nums">{{ row.client_code ?? '—' }}</span>
            </template>
            <template #cell-name="{ row }">
                <button
                    v-if="can.edit"
                    type="button"
                    class="text-left font-medium text-brand-700 hover:underline"
                    @click="edit(row)"
                >
                    {{ row.name }}
                </button>
                <span v-else>{{ row.name }}</span>
                <span class="block text-xs text-surface-500">{{ row.address_line }}</span>
            </template>
            <template #cell-contact_person="{ row }">
                <span v-if="row.contact_person">{{ row.contact_person }}</span>
                <span
                    v-if="row.contacts.length > 1"
                    class="block text-xs text-surface-500"
                >+{{ row.contacts.length - 1 }} more</span>
                <span v-if="!row.contact_person">—</span>
            </template>
            <template #cell-status="{ row }">
                <StatusChip
                    :status="row.status"
                    :label="statusLabel(row.status)"
                    :tone="statusTone(row.status)"
                />
            </template>
        </DataTable>

        <Modal
            :open="open"
            :title="editing ? `Edit Client — ${editing.name}` : 'Add New Client'"
            size="xl"
            @close="open = false"
        >
            <p class="-mt-1 mb-2.5 text-sm text-surface-500">
                {{ editing ? 'Update this client\'s details. Documents already issued keep the details they were issued with.' : 'Fill in the details below to register a new client.' }}
            </p>
            <form
                id="client-form"
                class="fields-compact grid grid-cols-1 gap-x-3 gap-y-2.5 sm:grid-cols-2 lg:grid-cols-4"
                novalidate
                @submit.prevent="save"
            >
                <FormField
                    id="c-code"
                    label="Client ID"
                >
                    <input
                        id="c-code"
                        :value="editing?.client_code ?? 'Auto-generated'"
                        readonly
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-name"
                    label="Client Name"
                    required
                    :error="form.errors.name"
                    class="sm:col-span-2"
                >
                    <input
                        id="c-name"
                        v-model="form.name"
                        data-autofocus
                        placeholder="e.g. ABC Sdn Bhd"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-status"
                    label="Client Status"
                    required
                    :error="form.errors.status"
                >
                    <select
                        id="c-status"
                        v-model="form.status"
                        class="w-full"
                    >
                        <option value="prospect">
                            Prospect
                        </option>
                        <option value="active">
                            Active
                        </option>
                        <option value="inactive">
                            Inactive
                        </option>
                    </select>
                </FormField>

                <FormField
                    id="c-type"
                    label="Client Type"
                    required
                    :error="form.errors.client_type"
                >
                    <select
                        id="c-type"
                        v-model="form.client_type"
                        class="w-full"
                    >
                        <option
                            value=""
                            disabled
                        >
                            Select type
                        </option>
                        <option
                            v-for="o in options.types"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="form.client_type === 'other'"
                    id="c-type-other"
                    label="Specify Client Type"
                    required
                    :error="form.errors.client_type_other"
                >
                    <input
                        id="c-type-other"
                        v-model="form.client_type_other"
                        maxlength="120"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-industry"
                    label="Industry / Sector"
                    :error="form.errors.industry"
                >
                    <select
                        id="c-industry"
                        v-model="form.industry"
                        class="w-full"
                    >
                        <option value="">
                            Select industry
                        </option>
                        <option
                            v-for="i in options.industries"
                            :key="i"
                            :value="i"
                        >
                            {{ i }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="form.industry === 'Others'"
                    id="c-industry-other"
                    label="Specify Industry"
                >
                    <input
                        id="c-industry-other"
                        v-model="form.industry_other"
                        maxlength="120"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-terms"
                    label="Payment Terms (days)"
                    required
                    :error="form.errors.payment_terms_days"
                >
                    <input
                        id="c-terms"
                        v-model="form.payment_terms_days"
                        type="number"
                        min="0"
                        max="365"
                        class="w-full"
                    >
                </FormField>

                <FormField
                    id="c-reg"
                    label="SSM / Registration No."
                    :error="form.errors.registration_no"
                >
                    <input
                        id="c-reg"
                        v-model="form.registration_no"
                        placeholder="e.g. 202601012345"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-tin"
                    label="TIN (Tax Identification No.)"
                    :error="form.errors.tax_no"
                >
                    <input
                        id="c-tin"
                        v-model="form.tax_no"
                        placeholder="e.g. C2584563200 — used on invoices"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-sst"
                    label="SST Registration No. (optional)"
                    :error="form.errors.sst_no"
                >
                    <input
                        id="c-sst"
                        v-model="form.sst_no"
                        placeholder="If registered"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-phone"
                    label="Company Phone"
                    :error="form.errors.phone"
                >
                    <input
                        id="c-phone"
                        v-model="form.phone"
                        type="tel"
                        placeholder="e.g. +60 3-1234 5678"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-email"
                    label="Company / Accounts Email"
                    :error="form.errors.email"
                    class="sm:col-span-2"
                >
                    <input
                        id="c-email"
                        v-model="form.email"
                        type="email"
                        placeholder="e.g. accounts@company.com"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-idtype"
                    label="e-Invoice ID Type"
                    :error="form.errors.einvoice_id_type"
                >
                    <select
                        id="c-idtype"
                        v-model="form.einvoice_id_type"
                        class="w-full"
                    >
                        <option value="">
                            Select ID type
                        </option>
                        <option
                            v-for="o in options.idTypes"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="c-idno"
                    label="e-Invoice ID Number"
                    :error="form.errors.einvoice_id_no"
                >
                    <input
                        id="c-idno"
                        v-model="form.einvoice_id_no"
                        :placeholder="form.einvoice_id_type === 'brn' ? 'Blank = same as SSM no.' : 'As on MyKad / passport'"
                        class="w-full"
                    >
                </FormField>

                <h3 class="pt-1 text-xs font-semibold uppercase tracking-wide text-surface-500 sm:col-span-2 lg:col-span-4">
                    Address
                </h3>
                <FormField
                    id="c-line1"
                    label="Address Line 1"
                    class="sm:col-span-2"
                >
                    <input
                        id="c-line1"
                        v-model="form.address.line1"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-line2"
                    label="Address Line 2"
                    class="sm:col-span-2"
                >
                    <input
                        id="c-line2"
                        v-model="form.address.line2"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-postcode"
                    label="Postcode"
                >
                    <input
                        id="c-postcode"
                        v-model="form.address.postcode"
                        maxlength="10"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-city"
                    label="City"
                >
                    <input
                        id="c-city"
                        v-model="form.address.city"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-state"
                    label="State"
                >
                    <select
                        v-if="isMalaysia"
                        id="c-state"
                        v-model="form.address.state"
                        class="w-full"
                    >
                        <option value="">
                            Select state
                        </option>
                        <option
                            v-for="s in options.states"
                            :key="s"
                            :value="s"
                        >
                            {{ s }}
                        </option>
                    </select>
                    <input
                        v-else
                        id="c-state"
                        v-model="form.address.state"
                        placeholder="State / province"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="c-country"
                    label="Country"
                >
                    <select
                        id="c-country"
                        v-model="form.address.country"
                        class="w-full"
                        @change="form.address.state = ''"
                    >
                        <option
                            v-for="c in options.countries"
                            :key="c"
                            :value="c"
                        >
                            {{ c }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="form.address.country === 'Others'"
                    id="c-country-other"
                    label="Specify Country"
                >
                    <input
                        id="c-country-other"
                        v-model="form.country_other"
                        class="w-full"
                    >
                </FormField>

                <div class="flex items-center justify-between pt-1 sm:col-span-2 lg:col-span-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-surface-500">
                        Contact Persons
                    </h3>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"
                        data-test="add-contact"
                        @click="addContact"
                    >
                        <Icon
                            name="plus"
                            :size="14"
                        /> Add contact
                    </button>
                </div>
                <div
                    v-for="(c, i) in form.contacts"
                    :key="i"
                    class="grid grid-cols-1 items-end gap-x-3 gap-y-2 sm:col-span-2 sm:grid-cols-2 lg:col-span-4 lg:grid-cols-[1.3fr_1fr_1.3fr_1fr_auto]"
                    :data-test="`contact-${i}`"
                >
                    <FormField
                        :id="`k-name-${i}`"
                        :label="i === 0 ? 'Name' : ''"
                        :error="errs[`contacts.${i}.name`]"
                    >
                        <input
                            :id="`k-name-${i}`"
                            v-model="c.name"
                            placeholder="e.g. Nurul Aisyah"
                            :aria-label="`Contact ${i + 1} name`"
                            class="w-full"
                        >
                    </FormField>
                    <FormField
                        :id="`k-pos-${i}`"
                        :label="i === 0 ? 'Position' : ''"
                    >
                        <input
                            :id="`k-pos-${i}`"
                            v-model="c.position"
                            placeholder="e.g. Finance Manager"
                            :aria-label="`Contact ${i + 1} position`"
                            class="w-full"
                        >
                    </FormField>
                    <FormField
                        :id="`k-email-${i}`"
                        :label="i === 0 ? 'Email' : ''"
                        :error="errs[`contacts.${i}.email`]"
                    >
                        <input
                            :id="`k-email-${i}`"
                            v-model="c.email"
                            type="email"
                            placeholder="name@company.com"
                            :aria-label="`Contact ${i + 1} email`"
                            class="w-full"
                        >
                    </FormField>
                    <FormField
                        :id="`k-phone-${i}`"
                        :label="i === 0 ? 'Phone' : ''"
                    >
                        <input
                            :id="`k-phone-${i}`"
                            v-model="c.phone"
                            type="tel"
                            placeholder="+60 12-345 6789"
                            :aria-label="`Contact ${i + 1} phone`"
                            class="w-full"
                        >
                    </FormField>
                    <div class="flex h-[2.3rem] items-center gap-2">
                        <label class="flex cursor-pointer items-center gap-1.5 whitespace-nowrap text-xs text-surface-700">
                            <input
                                type="radio"
                                name="primary-contact"
                                :checked="c.is_primary"
                                class="accent-brand-600"
                                @change="makePrimary(i)"
                            > Primary
                        </label>
                        <button
                            v-if="form.contacts.length > 1"
                            type="button"
                            class="rounded p-1 text-surface-500 hover:bg-red-50 hover:text-red-700"
                            :aria-label="`Remove contact ${i + 1}`"
                            @click="removeContact(i)"
                        >
                            <Icon
                                name="close"
                                :size="16"
                            />
                        </button>
                    </div>
                </div>
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
                    form="client-form"
                    :disabled="form.processing"
                    data-test="save-client"
                >
                    Save Client
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
