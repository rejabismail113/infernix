<script setup lang="ts">
/** Purchase requests (M10-02): request → approval → vendor PO → goods receipt → bill → payment. */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { lineAmounts, sumDecimals } from '@/lib/decimal';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';
import type { Option } from '@/types/operations';
import type { TableColumn } from '@/types/table';

interface Row { id: number; pr_no: string; title: string; status: string; estimated_total: string; needed_by: string | null; vendor: string | null; vendor_po_id: number | null; created_at: string | null }
const props = defineProps<{ requests: Row[]; vendors: Option[]; projects: Option[]; activities: Option[]; openOrders: Option[]; filters: Record<string, string | null>; can: { create: boolean; receive: boolean } }>();
const columns: TableColumn[] = [{ key: 'pr_no', label: 'PR', sortable: true }, { key: 'title', label: 'Title', sortable: true }, { key: 'vendor', label: 'Vendor' },
    { key: 'needed_by', label: 'Needed by', sortable: true }, { key: 'estimated_total', label: 'Estimate', numeric: true, sortable: true }, { key: 'status', label: 'Status', sortable: true }, { key: 'created_at', label: 'Raised' }];
const CLASSES = ['EXPENSE', 'CONSUMABLE', 'LOW_VALUE_EQUIPMENT', 'CAPITAL_ASSET'];
const open = ref(false);
const blankLine = () => ({ description: '', quantity: '1', estimated_unit_price: '', capitalization_class: 'EXPENSE' });
const form = useForm({ title: '', justification: '', vendor_id: '' as number | '', project_id: '' as number | '', activity_id: '' as number | '', needed_by: '', lines: [blankLine()] });
const total = computed(() => sumDecimals(form.lines.map((l) => lineAmounts(l.quantity || '0', l.estimated_unit_price || '0', '0', '0').total)));
const save = () => form.transform((d) => blanksToNull(d, ['vendor_id', 'project_id', 'activity_id', 'needed_by'])).post(route('procurement.requests.store'));
const status = ref(props.filters.status ?? '');
</script>

<template>
    <AppLayout
        title="Purchase requests"
        :breadcrumbs="[{ label: 'Procurement' }, { label: 'Purchase requests' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'procurement.pipeline')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Purchase pipeline
            </Link>
            <Button
                v-if="can.create"
                data-test="add-pr"
                @click="open = true"
            >
                New request
            </Button>
        </template>
        <div class="mb-3 flex gap-2 text-sm">
            <select
                v-model="status"
                aria-label="Status"
                class="rounded-md border border-surface-300 px-2 py-2"
                @change="router.get(route('procurement.requests.index'), status ? { status } : {}, { preserveState: true })"
            >
                <option value="">
                    Any status
                </option>
                <option
                    v-for="s in ['draft', 'pending_approval', 'approved', 'ordered', 'rejected', 'cancelled']"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace('_', ' ') }}
                </option>
            </select>
        </div>
        <DataTable
            :columns="columns"
            :rows="requests"
            prefs-key="purchase-requests"
            empty-text="No purchase requests."
        >
            <template #cell-pr_no="{ row }">
                <Link
                    :href="route('procurement.requests.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.pr_no }}
                </Link>
            </template>
            <template #cell-estimated_total="{ row }">
                <Amount :value="row.estimated_total" />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>

        <Modal
            :open="open"
            title="New purchase request"
            size="xl"
            @close="open = false"
        >
            <form
                id="pr-form"
                class="space-y-3"
                @submit.prevent="save"
            >
                <SchemaFields
                    :fields="[{ key: 'title', label: 'What is needed', type: 'text', required: true, span: 2 }, { key: 'vendor_id', label: 'Suggested vendor', type: 'select', options: vendors.map((v) => ({ value: v.id, label: v.label })) },
                              { key: 'needed_by', label: 'Needed by', type: 'date' }, { key: 'project_id', label: 'Project', type: 'select', options: projects.map((p) => ({ value: p.id, label: p.label })) },
                              { key: 'activity_id', label: 'Activity', type: 'select', options: activities.map((a) => ({ value: a.id, label: a.label })) }, { key: 'justification', label: 'Justification', type: 'textarea', required: true }]"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="pr"
                    @set="(k, v) => setField(form, k, v)"
                />
                <table class="w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr>
                            <th>Description</th><th class="w-20">
                                Qty
                            </th><th class="w-36">
                                Est. unit price
                            </th><th class="w-44">
                                Classification
                            </th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(l, i) in form.lines"
                            :key="i"
                        >
                            <td>
                                <input
                                    v-model="l.description"
                                    :aria-label="`Line ${i + 1} description`"
                                    class="w-full rounded border border-surface-300 px-2 py-1"
                                >
                            </td>
                            <td>
                                <input
                                    v-model="l.quantity"
                                    inputmode="decimal"
                                    :aria-label="`Line ${i + 1} quantity`"
                                    class="w-full rounded border border-surface-300 px-2 py-1"
                                >
                            </td>
                            <td>
                                <MoneyInput v-model="l.estimated_unit_price" />
                            </td>
                            <td>
                                <select
                                    v-model="l.capitalization_class"
                                    :aria-label="`Line ${i + 1} class`"
                                    class="w-full rounded border border-surface-300 px-2 py-1"
                                >
                                    <option
                                        v-for="c in CLASSES"
                                        :key="c"
                                        :value="c"
                                    >
                                        {{ c.replace(/_/g, ' ').toLowerCase() }}
                                    </option>
                                </select>
                            </td>
                            <td>
                                <button
                                    v-if="form.lines.length > 1"
                                    type="button"
                                    class="text-red-700"
                                    aria-label="Remove line"
                                    @click="form.lines.splice(i, 1)"
                                >
                                    ×
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex items-center justify-between">
                    <Button
                        variant="secondary"
                        @click="form.lines.push(blankLine())"
                    >
                        Add line
                    </Button>
                    <span class="text-sm">Estimated total <Amount :value="total" /></span>
                </div>
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="pr-form"
                    :disabled="form.processing"
                >
                    Save draft
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
