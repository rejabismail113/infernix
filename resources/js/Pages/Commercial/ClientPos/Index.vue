<script setup lang="ts">
/** Incoming client POs / LOAs (M03-05) — distinct from outgoing vendor POs. */
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';

interface Po { id: number; number: string; client: string; title: string | null; external_ref: string | null; po_date: string | null; total: string; quotation_id: number | null; project_id: number | null; status: string }
const props = defineProps<{ pos: Po[]; clients: { id: number; label: string }[]; quotations: { id: number; label: string; client_id: number | null }[]; projects: { id: number; label: string; client_id?: number }[]; preselect: { quotation_id: number | null }; can: { create: boolean } }>();
const pre = props.quotations.find((q) => q.id === props.preselect.quotation_id);
const open = ref(!!pre && props.can.create);
const form = useForm({ client_id: (pre?.client_id ?? '') as number | '', external_ref: '', po_date: new Date().toISOString().slice(0, 10), amount: '' as string | number | null, quotation_id: (pre?.id ?? '') as number | '', project_id: '' as number | '', title: '' });
const quotes = computed(() => props.quotations.filter((q) => !form.client_id || q.client_id === form.client_id));
const save = () => form.transform((d) => ({ ...d, amount: String(d.amount ?? ''), quotation_id: d.quotation_id || null, project_id: d.project_id || null })).post(route('commercial.client-pos.store'), { onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <AppLayout
        title="Client POs / LOAs"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Client POs / LOAs' }]"
    >
        <template #actions>
            <Button
                v-if="can.create"
                data-test="record-po"
                @click="open = true"
            >
                Record client PO
            </Button>
        </template>
        <p class="text-sm text-surface-600">
            Purchase orders and letters of award received from clients. Attach the scanned copy on the quotation or project. Outgoing orders to suppliers are <Link
                :href="route('commercial.vendor-pos.index')"
                class="text-brand-700 underline"
            >
                vendor POs
            </Link>.
        </p>
        <DataTable
            :columns="[{ key: 'number', label: 'Our ref', hideable: false }, { key: 'external_ref', label: 'Client PO / LOA no.' }, { key: 'po_date', label: 'Date' }, { key: 'client', label: 'Client' }, { key: 'title', label: 'Title' }, { key: 'total', label: 'Amount', numeric: true }, { key: 'links', label: 'Linked to' }]"
            :rows="pos"
            empty-text="No client POs recorded."
        >
            <template #cell-total="{ row }">
                <Amount :value="row.total" />
            </template>
            <template #cell-links="{ row }">
                <Link
                    v-if="row.quotation_id"
                    :href="route('commercial.quotations.show', row.quotation_id)"
                    class="mr-2 text-brand-700 hover:underline"
                >
                    Quotation
                </Link>
                <Link
                    v-if="row.project_id"
                    :href="route('projects.projects.show', row.project_id)"
                    class="text-brand-700 hover:underline"
                >
                    Project
                </Link>
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Record client PO / LOA"
            size="md"
            @close="open = false"
        >
            <p
                v-if="fieldError(form.errors, 'general')"
                class="mb-2 rounded bg-red-50 p-2 text-sm text-red-800"
            >
                {{ fieldError(form.errors, 'general') }}
            </p>
            <form
                id="po-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="save"
            >
                <FormField
                    id="po-client"
                    label="Client"
                    :error="form.errors.client_id"
                    class="col-span-2"
                >
                    <select
                        id="po-client"
                        v-model="form.client_id"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="c in clients"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="po-ref"
                    label="Client's PO / LOA number"
                    :error="form.errors.external_ref"
                >
                    <input
                        id="po-ref"
                        v-model="form.external_ref"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="po-date"
                    label="Date"
                >
                    <input
                        id="po-date"
                        v-model="form.po_date"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="po-amount"
                    label="Amount"
                    :error="form.errors.amount"
                >
                    <MoneyInput
                        id="po-amount"
                        v-model="form.amount"
                    />
                </FormField>
                <FormField
                    id="po-quote"
                    label="Quotation"
                >
                    <select
                        id="po-quote"
                        v-model="form.quotation_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            —
                        </option>
                        <option
                            v-for="q in quotes"
                            :key="q.id"
                            :value="q.id"
                        >
                            {{ q.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="po-project"
                    label="Project"
                    class="col-span-2"
                >
                    <select
                        id="po-project"
                        v-model="form.project_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            —
                        </option>
                        <option
                            v-for="p in projects.filter((x) => !form.client_id || x.client_id === form.client_id)"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.label }}
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
                    form="po-form"
                    :disabled="form.processing"
                >
                    Record
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
