<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ClientRow { id: number; name: string; registration_no: string | null; tax_no: string | null; email: string | null; phone: string | null; contact_person: string | null; address: Record<string, string> | null; address_line: string; payment_terms_days: number; status: string; projects: number }
const props = defineProps<{ clients: ClientRow[]; filters: Record<string, string | null>; can: { create: boolean; edit: boolean } }>();
const f = reactive({ q: props.filters.q ?? '' });
const editing = ref<ClientRow | null>(null);
const open = ref(false);
const blank = { name: '', registration_no: '', tax_no: '', email: '', phone: '', contact_person: '', payment_terms_days: 30, status: 'active', address: { line1: '', line2: '', postcode: '', city: '', state: '', country: 'Malaysia' } };
const form = useForm(structuredClone(blank));
function edit(c: ClientRow | null) {
    editing.value = c;
    const src = c ? { ...blank, ...c, address: { ...blank.address, ...(c.address ?? {}) }, registration_no: c.registration_no ?? '', tax_no: c.tax_no ?? '', email: c.email ?? '', phone: c.phone ?? '', contact_person: c.contact_person ?? '' } : structuredClone(blank);
    form.defaults(src); form.reset(); form.clearErrors();
    open.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    if (editing.value) form.put(route('projects.clients.update', editing.value.id), opts); else form.post(route('projects.clients.store'), opts);
}
</script>

<template>
    <AppLayout
        title="Clients"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Clients' }]"
    >
        <template #actions>
            <Button
                v-if="can.create"
                data-test="add-client"
                @click="edit(null)"
            >
                Add client
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
                placeholder="Name, reg. no. or email"
                aria-label="Search"
                class="w-72 rounded-md border border-surface-300 px-3 py-2"
            >
            <Button type="submit">
                Search
            </Button>
        </form>
        <DataTable
            :columns="[{ key: 'name', label: 'Client', sortable: true, hideable: false }, { key: 'registration_no', label: 'SSM / reg. no.' }, { key: 'contact_person', label: 'Contact' }, { key: 'email', label: 'Email' }, { key: 'payment_terms_days', label: 'Terms (days)', numeric: true }, { key: 'projects', label: 'Projects', numeric: true }, { key: 'status', label: 'Status' }]"
            :rows="clients"
            prefs-key="projects.clients"
            empty-text="No clients yet."
        >
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
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            :title="editing ? `Edit ${editing.name}` : 'Add client'"
            size="lg"
            @close="open = false"
        >
            <p
                v-if="editing"
                class="mb-3 text-xs text-surface-500"
            >
                Changes apply to new documents only; issued quotations and invoices keep the details they were issued with.
            </p>
            <form
                id="client-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="save"
            >
                <FormField
                    id="c-name"
                    label="Name"
                    :error="form.errors.name"
                    class="col-span-2"
                >
                    <input
                        id="c-name"
                        v-model="form.name"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    v-for="fld in [['registration_no', 'SSM / reg. no.'], ['tax_no', 'Tax no.'], ['email', 'Email'], ['phone', 'Phone'], ['contact_person', 'Contact person']]"
                    :id="`c-${fld[0]}`"
                    :key="fld[0]"
                    :label="fld[1]"
                    :error="(form.errors as Record<string, string>)[fld[0]]"
                >
                    <input
                        :id="`c-${fld[0]}`"
                        v-model="(form as unknown as Record<string, string>)[fld[0]]"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-terms"
                    label="Payment terms (days)"
                    :error="form.errors.payment_terms_days"
                >
                    <input
                        id="c-terms"
                        v-model.number="form.payment_terms_days"
                        type="number"
                        min="0"
                        max="365"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-line1"
                    label="Address line 1"
                    class="col-span-2"
                >
                    <input
                        id="c-line1"
                        v-model="form.address.line1"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-line2"
                    label="Address line 2"
                    class="col-span-2"
                >
                    <input
                        id="c-line2"
                        v-model="form.address.line2"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-postcode"
                    label="Postcode"
                >
                    <input
                        id="c-postcode"
                        v-model="form.address.postcode"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-city"
                    label="City"
                >
                    <input
                        id="c-city"
                        v-model="form.address.city"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-state"
                    label="State"
                >
                    <input
                        id="c-state"
                        v-model="form.address.state"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    v-if="editing"
                    id="c-status"
                    label="Status"
                >
                    <select
                        id="c-status"
                        v-model="form.status"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="active">
                            Active
                        </option><option value="inactive">
                            Inactive
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
                    form="client-form"
                    :disabled="form.processing"
                >
                    Save client
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
