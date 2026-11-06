<script setup lang="ts">
/** CSR programmes (M08): cash spend (linked via the programme activity) shown apart from in-kind estimates. */
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { blanksToNull, setField } from '@/lib/forms';
import type { TableColumn } from '@/types/table';

interface Row { id: number; programme_no: string; title: string; programme_date: string; partner: string | null; venue: string | null; beneficiary_group: string | null; status: string; budget: string; cash: string; in_kind: string; volunteer_hours: string }
defineProps<{ programmes: Row[]; can: { create: boolean; export: boolean }; year: number }>();
const columns: TableColumn[] = [
    { key: 'programme_no', label: 'No', sortable: true }, { key: 'title', label: 'Programme', sortable: true }, { key: 'programme_date', label: 'Date', sortable: true },
    { key: 'partner', label: 'Partner' }, { key: 'beneficiary_group', label: 'Beneficiaries' }, { key: 'cash', label: 'Cash spend', numeric: true },
    { key: 'in_kind', label: 'In-kind (est.)', numeric: true }, { key: 'status', label: 'Status' },
];
const open = ref(false);
const form = useForm({ title: '', programme_date: new Date().toISOString().slice(0, 10), objectives: '', partner: '', sponsor: '', venue: '', beneficiary_group: '', target_count: '', budget: '' });
const save = () => form.transform((d) => blanksToNull(d, ['target_count', 'budget'])).post(route('csr.programmes.store'));
const reportYear = ref(new Date().getFullYear());
</script>

<template>
    <AppLayout
        title="CSR programmes"
        :breadcrumbs="[{ label: 'Operations' }, { label: 'CSR' }]"
    >
        <template #actions>
            <span
                v-if="can.export"
                class="flex items-center gap-1 text-sm"
            >
                <input
                    v-model.number="reportYear"
                    type="number"
                    min="2020"
                    max="2100"
                    aria-label="Report year"
                    class="w-20 rounded border border-surface-300 px-2 py-1"
                >
                <a
                    :href="route('csr.annual-report', { year: reportYear })"
                    class="font-medium text-brand-700 hover:underline"
                    data-test="csr-annual-report"
                >Annual report (PDF)</a>
            </span>
            <Link
                :href="route('reports.view', 'csr.spend')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                CSR spend
            </Link>
            <Button
                v-if="can.create"
                data-test="add-programme"
                @click="open = true"
            >
                New programme
            </Button>
        </template>
        <DataTable
            :columns="columns"
            :rows="programmes"
            prefs-key="csr"
            empty-text="No CSR programmes yet."
        >
            <template #cell-programme_no="{ row }">
                <Link
                    :href="route('csr.programmes.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.programme_no }}
                </Link>
            </template>
            <template #cell-cash="{ row }">
                <Amount :value="row.cash" />
            </template>
            <template #cell-in_kind="{ row }">
                <Amount :value="row.in_kind" />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="New CSR programme"
            @close="open = false"
        >
            <form
                id="csr-form"
                @submit.prevent="save"
            >
                <SchemaFields
                    :fields="[{ key: 'title', label: 'Title', type: 'text', required: true, span: 2 }, { key: 'programme_date', label: 'Date', type: 'date', required: true }, { key: 'venue', label: 'Venue', type: 'text' },
                              { key: 'partner', label: 'Partner', type: 'text' }, { key: 'sponsor', label: 'Sponsor', type: 'text' }, { key: 'beneficiary_group', label: 'Beneficiary group', type: 'text' },
                              { key: 'target_count', label: 'Target beneficiaries', type: 'number' }, { key: 'budget', label: 'Cash budget (RM)', type: 'money', help: 'Cash spend is linked from Finance through the programme activity.' },
                              { key: 'objectives', label: 'Objectives', type: 'textarea' }]"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="csr"
                    @set="(k, v) => setField(form, k, v)"
                />
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="csr-form"
                    :disabled="form.processing"
                >
                    Create
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
