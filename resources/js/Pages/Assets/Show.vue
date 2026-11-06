<script setup lang="ts">
/**
 * Asset detail (M05): purchase reference (no second expense), custodian / handover history, maintenance with
 * repair cost LINKED to Finance, depreciation schedule, disposal workflow (journal via PostingService), documents.
 */
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import FinanceLinker from '@/Components/Finance/FinanceLinker.vue';
import LinkedRecords from '@/Components/Finance/LinkedRecords.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';
import type { LinkedDoc } from '@/types/finance';
import type { FinanceRecordSnapshot, Option } from '@/types/operations';

interface Asset { id: number; asset_no: string; name: string; category: string | null; asset_class: string; serial_no: string | null; status: string; location: string | null; acquired_on: string; cost: string; warranty_expires_on: string | null; custodian: string | null; source: string; description: string | null; model: string | null; acquisition_type: string; residual_value: string; useful_life_months: number; depreciation_method: string; notes: string | null; vendor_id: number | null; project_id: number | null; category_id: number | null }
interface Disposal { id: number; disposal_no: string; method: string; disposal_date: string; reason: string; status: string; current_level: number; expected_proceeds: string; nbv: string | null; journal: { id: number; journal_no: string } | null; proceeds: FinanceRecordSnapshot[]; created_by: number }
const props = defineProps<{
    asset: Asset; purchase: FinanceRecordSnapshot | null;
    assignments: { id: number; custodian: string | null; location: string | null; assigned_on: string; expected_return_on: string | null; returned_on: string | null; condition_out: string | null; condition_in: string | null; note: string | null }[];
    maintenance: { id: number; kind: string; reported_on: string; completed_on: string | null; description: string; status: string; estimated_cost: string | null; vendor: string | null; costs: FinanceRecordSnapshot[] }[];
    depreciation: { period_end: string; amount: string; accumulated: string; nbv: string; posted: boolean }[];
    disposals: Disposal[]; documents: LinkedDoc[]; staff: Option[]; vendors: Option[]; projects: Option[]; categories: { id: number; name: string }[]; postingEnabled: boolean;
    can: { edit: boolean; dispose: boolean; approve: boolean; link_finance: boolean };
}>();
const tab = ref<'history' | 'maintenance' | 'depreciation' | 'disposal' | 'documents'>('history');
const openAssignment = () => props.assignments.find((a) => a.returned_on === null) ?? null;
const today = new Date().toISOString().slice(0, 10);

const editOpen = ref(false);
const edit = useForm({ name: props.asset.name, description: props.asset.description ?? '', category_id: (props.asset.category_id ?? '') as number | '', serial_no: props.asset.serial_no ?? '', model: props.asset.model ?? '', location: props.asset.location ?? '', warranty_expires_on: props.asset.warranty_expires_on ?? '', vendor_id: (props.asset.vendor_id ?? '') as number | '', project_id: (props.asset.project_id ?? '') as number | '', notes: props.asset.notes ?? '' });
const saveEdit = () => edit.transform((d) => blanksToNull(d, ['category_id', 'warranty_expires_on', 'vendor_id', 'project_id'])).put(route('assets.assets.update', props.asset.id), { onSuccess: () => { editOpen.value = false; } });

const assign = useForm({ staff_profile_id: '' as number | '', location: '', assigned_on: today, expected_return_on: '', condition_out: 'Good', note: '' });
const doAssign = () => assign.transform((d) => blanksToNull(d, ['staff_profile_id', 'expected_return_on'])).post(route('assets.assets.assign', props.asset.id), { preserveScroll: true, onSuccess: () => assign.reset() });
const ret = useForm({ returned_on: today, condition_in: 'Good', note: '', location: '' });
const doReturn = () => ret.post(route('assets.assets.return', props.asset.id), { preserveScroll: true });

const maint = useForm({ kind: 'repair', reported_on: today, description: '', vendor_id: '' as number | '', estimated_cost: '' });
const doMaint = () => maint.transform((d) => blanksToNull(d, ['vendor_id', 'estimated_cost'])).post(route('assets.assets.maintenance', props.asset.id), { preserveScroll: true, onSuccess: () => maint.reset() });
const complete = (id: number) => router.post(route('assets.maintenance.complete', id), { completed_on: today }, { preserveScroll: true });

const disposeOpen = ref(false);
const disp = useForm({ method: 'sale', disposal_date: today, reason: '', expected_proceeds: '' });
const doDispose = () => disp.post(route('assets.disposals.store', props.asset.id), { preserveScroll: true, onSuccess: () => { disposeOpen.value = false; tab.value = 'disposal'; } });
const act = (d: Disposal, action: 'submit' | 'approve' | 'reject' | 'cancel') => {
    const payload: Record<string, string> = {};
    if (action === 'reject' || action === 'cancel') { const r = window.prompt('Reason?'); if (!r) return; payload.reason = r; }
    router.post(route(`assets.disposals.${action}`, d.id), payload, { preserveScroll: true });
};
</script>

<template>
    <AppLayout
        :title="`${asset.asset_no} · ${asset.name}`"
        :breadcrumbs="[{ label: 'Operations' }, { label: 'Assets', href: route('assets.assets.index') }, { label: asset.asset_no }]"
        :status="asset.status"
    >
        <template #actions>
            <Button
                v-if="can.edit"
                variant="secondary"
                @click="editOpen = true"
            >
                Edit
            </Button>
            <Button
                v-if="can.dispose && !disposals.some((d) => !['rejected', 'cancelled'].includes(d.status))"
                variant="danger"
                data-test="start-disposal"
                @click="disposeOpen = true"
            >
                Dispose
            </Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card
                title="Details"
                class="lg:col-span-2"
            >
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Category
                        </dt><dd>{{ asset.category ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Class
                        </dt><dd>{{ asset.asset_class.replace('_', ' ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Serial / model
                        </dt><dd>{{ asset.serial_no ?? '—' }} {{ asset.model ? `· ${asset.model}` : '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Custodian
                        </dt><dd>{{ asset.custodian ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Location
                        </dt><dd>{{ asset.location ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Warranty ends
                        </dt><dd>{{ asset.warranty_expires_on ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Acquired
                        </dt><dd>{{ asset.acquired_on }} ({{ asset.acquisition_type.replace('_', ' ') }})</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Cost
                        </dt><dd class="font-mono">
                            <Amount :value="asset.cost" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Useful life
                        </dt><dd>{{ asset.useful_life_months }} months · residual <Amount :value="asset.residual_value" /></dd>
                    </div>
                </dl>
                <p
                    v-if="asset.description"
                    class="mt-3 text-sm"
                >
                    {{ asset.description }}
                </p>
            </Card>
            <Card
                title="Purchase"
                description="The cost lives in the ledger once; this asset only references it."
            >
                <LinkedRecords
                    :records="purchase ? [purchase] : []"
                    empty="Not purchased (opening balance or donation)."
                />
            </Card>
        </div>

        <nav
            class="mt-6 flex gap-4 border-b border-surface-200 text-sm"
            role="tablist"
        >
            <button
                v-for="t in (['history', 'maintenance', 'depreciation', 'disposal', 'documents'] as const)"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="tab === t"
                :data-test="`tab-${t}`"
                class="-mb-px border-b-2 px-1 py-2 capitalize"
                :class="tab === t ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-surface-500 hover:text-surface-800'"
                @click="tab = t"
            >
                {{ t }}
            </button>
        </nav>

        <section
            v-if="tab === 'history'"
            class="mt-4 grid gap-4 lg:grid-cols-3"
        >
            <Card
                title="Custodian & location history"
                class="lg:col-span-2"
            >
                <table class="w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr><th>Custodian / location</th><th>From</th><th>Returned</th><th>Condition</th></tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in assignments"
                            :key="a.id"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1">
                                {{ a.custodian ?? a.location }}
                            </td>
                            <td>{{ a.assigned_on }}</td>
                            <td>{{ a.returned_on ?? (a.expected_return_on ? `due ${a.expected_return_on}` : 'in use') }}</td>
                            <td>{{ a.condition_out ?? '—' }} → {{ a.condition_in ?? '…' }}</td>
                        </tr>
                        <tr v-if="!assignments.length">
                            <td
                                colspan="4"
                                class="py-2 text-surface-500"
                            >
                                Never assigned.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <Card
                v-if="can.edit"
                :title="openAssignment() ? 'Record return / handover' : 'Assign'"
            >
                <form
                    v-if="!openAssignment()"
                    class="space-y-2"
                    @submit.prevent="doAssign"
                >
                    <SchemaFields
                        :fields="[{ key: 'staff_profile_id', label: 'Custodian', type: 'select', options: staff.map((s) => ({ value: s.id, label: s.label })), span: 2 },
                                  { key: 'location', label: 'Location', type: 'text', span: 2 }, { key: 'assigned_on', label: 'From', type: 'date' }, { key: 'expected_return_on', label: 'Expected return', type: 'date' },
                                  { key: 'condition_out', label: 'Condition', type: 'text', span: 2 }]"
                        :values="assign"
                        :errors="assign.errors"
                        id-prefix="as"
                        @set="(k, v) => setField(assign, k, v)"
                    />
                    <p
                        v-if="fieldError(assign.errors, 'general')"
                        class="text-sm text-red-700"
                        role="alert"
                    >
                        {{ fieldError(assign.errors, 'general') }}
                    </p>
                    <Button
                        type="submit"
                        data-test="assign"
                    >
                        Assign
                    </Button>
                </form>
                <form
                    v-else
                    class="space-y-2"
                    @submit.prevent="doReturn"
                >
                    <SchemaFields
                        :fields="[{ key: 'returned_on', label: 'Returned on', type: 'date' }, { key: 'condition_in', label: 'Condition', type: 'text' }, { key: 'location', label: 'Now stored at', type: 'text', span: 2 }, { key: 'note', label: 'Note', type: 'textarea' }]"
                        :values="ret"
                        :errors="ret.errors"
                        id-prefix="rt"
                        @set="(k, v) => setField(ret, k, v)"
                    />
                    <Button type="submit">
                        Record return
                    </Button>
                </form>
            </Card>
        </section>

        <section
            v-if="tab === 'maintenance'"
            class="mt-4 space-y-4"
        >
            <Card
                v-for="m in maintenance"
                :key="m.id"
                :title="`${m.kind} · ${m.reported_on}`"
            >
                <template #actions>
                    <StatusChip :status="m.status" />
                    <Button
                        v-if="can.edit && m.status === 'open'"
                        variant="secondary"
                        @click="complete(m.id)"
                    >
                        Mark completed
                    </Button>
                </template>
                <p class="text-sm">
                    {{ m.description }} <span class="text-surface-500">{{ m.vendor ? `· ${m.vendor}` : '' }} {{ m.estimated_cost ? `· estimate RM ${m.estimated_cost}` : '' }}</span>
                </p>
                <div class="mt-2">
                    <LinkedRecords
                        :records="m.costs"
                        empty="No repair cost linked yet."
                    />
                </div>
                <FinanceLinker
                    v-if="can.link_finance"
                    class="mt-2"
                    :link-url="route('assets.maintenance.link', m.id)"
                    :types="['bill', 'transaction', 'claim']"
                    label="Link the repair cost"
                />
            </Card>
            <Card
                v-if="can.edit"
                title="Log maintenance / repair"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="doMaint"
                >
                    <SchemaFields
                        :fields="[{ key: 'kind', label: 'Type', type: 'select', options: ['repair', 'service', 'inspection', 'upgrade'].map((k) => ({ value: k, label: k })) }, { key: 'reported_on', label: 'Reported', type: 'date' },
                                  { key: 'vendor_id', label: 'Vendor', type: 'select', options: vendors.map((v) => ({ value: v.id, label: v.label })) }, { key: 'estimated_cost', label: 'Estimate (RM)', type: 'money' },
                                  { key: 'description', label: 'Description', type: 'textarea', required: true }]"
                        :values="maint"
                        :errors="maint.errors"
                        id-prefix="mt"
                        @set="(k, v) => setField(maint, k, v)"
                    />
                    <Button type="submit">
                        Log
                    </Button>
                </form>
            </Card>
        </section>

        <section
            v-if="tab === 'depreciation'"
            class="mt-4"
        >
            <Card :description="postingEnabled ? 'Posted monthly to the ledger.' : 'Schedule only — posting is switched off pending the accountant\'s decision.'">
                <table class="w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr>
                            <th>Month end</th><th class="text-right">
                                Depreciation
                            </th><th class="text-right">
                                Accumulated
                            </th><th class="text-right">
                                NBV
                            </th><th>Posted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in depreciation"
                            :key="d.period_end"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1">
                                {{ d.period_end }}
                            </td>
                            <td class="text-right font-mono">
                                <Amount :value="d.amount" />
                            </td>
                            <td class="text-right font-mono">
                                <Amount :value="d.accumulated" />
                            </td>
                            <td class="text-right font-mono">
                                <Amount :value="d.nbv" />
                            </td>
                            <td>{{ d.posted ? 'yes' : '—' }}</td>
                        </tr>
                        <tr v-if="!depreciation.length">
                            <td
                                colspan="5"
                                class="py-2 text-surface-500"
                            >
                                Not depreciated (low-value equipment or no useful life).
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </section>

        <section
            v-if="tab === 'disposal'"
            class="mt-4 space-y-4"
        >
            <Card
                v-for="d in disposals"
                :key="d.id"
                :title="`${d.disposal_no} · ${d.method} on ${d.disposal_date}`"
            >
                <template #actions>
                    <StatusChip :status="d.status" />
                    <Button
                        v-if="d.status === 'draft' && can.dispose"
                        @click="act(d, 'submit')"
                    >
                        Submit
                    </Button>
                    <Button
                        v-if="d.status === 'pending_approval' && can.approve"
                        :data-test="`approve-disposal-${d.id}`"
                        @click="act(d, 'approve')"
                    >
                        Approve L{{ d.current_level }}
                    </Button>
                    <Button
                        v-if="d.status === 'pending_approval' && can.approve"
                        variant="danger"
                        @click="act(d, 'reject')"
                    >
                        Reject
                    </Button>
                    <Button
                        v-if="['draft', 'pending_approval'].includes(d.status) && can.dispose"
                        variant="secondary"
                        @click="act(d, 'cancel')"
                    >
                        Cancel
                    </Button>
                </template>
                <p class="text-sm">
                    {{ d.reason }}
                </p>
                <p class="mt-1 text-sm text-surface-600">
                    Expected proceeds <Amount :value="d.expected_proceeds" /> <span v-if="d.nbv">· NBV written off <Amount :value="d.nbv" /></span>
                    <span v-if="d.journal"> · journal <a
                        :href="route('finance.journals.show', d.journal.id)"
                        class="text-brand-700 hover:underline"
                    >{{ d.journal.journal_no }}</a></span>
                </p>
                <div
                    v-if="d.status === 'approved'"
                    class="mt-2 space-y-2"
                >
                    <LinkedRecords
                        :records="d.proceeds"
                        empty="No proceeds receipt linked."
                    />
                    <FinanceLinker
                        v-if="can.link_finance"
                        :link-url="route('assets.disposals.proceeds', d.id)"
                        :types="['transaction']"
                        label="Link the proceeds receipt (record it in Finance as other income first)"
                    />
                </div>
            </Card>
            <p
                v-if="!disposals.length"
                class="text-sm text-surface-500"
            >
                No disposal recorded.
            </p>
        </section>

        <section
            v-if="tab === 'documents'"
            class="mt-4"
        >
            <Card title="Receipts, warranty & handover documents">
                <EvidencePanel
                    entity-type="asset"
                    :entity-id="asset.id"
                    link-type="warranty"
                    :documents="documents"
                    :can-attach="can.edit"
                />
            </Card>
        </section>

        <Modal
            :open="editOpen"
            title="Edit asset"
            @close="editOpen = false"
        >
            <form
                id="edit-asset"
                @submit.prevent="saveEdit"
            >
                <SchemaFields
                    :fields="[{ key: 'name', label: 'Name', type: 'text', required: true, span: 2 }, { key: 'category_id', label: 'Category', type: 'select', options: categories.map((c) => ({ value: c.id, label: c.name })) },
                              { key: 'serial_no', label: 'Serial no', type: 'text' }, { key: 'model', label: 'Model', type: 'text' }, { key: 'location', label: 'Location', type: 'text' },
                              { key: 'warranty_expires_on', label: 'Warranty ends', type: 'date' }, { key: 'vendor_id', label: 'Vendor', type: 'select', options: vendors.map((v) => ({ value: v.id, label: v.label })) },
                              { key: 'project_id', label: 'Project', type: 'select', options: projects.map((p) => ({ value: p.id, label: p.label })) }, { key: 'description', label: 'Description', type: 'textarea' }, { key: 'notes', label: 'Notes', type: 'textarea' }]"
                    :values="edit"
                    :errors="edit.errors"
                    id-prefix="ed"
                    @set="(k, v) => setField(edit, k, v)"
                />
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="edit-asset"
                    :disabled="edit.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>

        <Modal
            :open="disposeOpen"
            title="Dispose of asset"
            size="md"
            @close="disposeOpen = false"
        >
            <form
                id="dispose-form"
                class="space-y-2"
                @submit.prevent="doDispose"
            >
                <SchemaFields
                    :fields="[{ key: 'method', label: 'Method', type: 'select', options: ['sale', 'scrap', 'donation', 'loss', 'trade_in'].map((m) => ({ value: m, label: m.replace('_', ' ') })), required: true },
                              { key: 'disposal_date', label: 'Date', type: 'date', required: true }, { key: 'expected_proceeds', label: 'Expected proceeds (RM)', type: 'money' }, { key: 'reason', label: 'Reason', type: 'textarea', required: true }]"
                    :values="disp"
                    :errors="disp.errors"
                    id-prefix="dp"
                    @set="(k, v) => setField(disp, k, v)"
                />
                <p class="text-xs text-surface-500">
                    On final approval the disposal journal is posted (asset off at cost, posted depreciation released, net book value as loss). Proceeds are recorded in Finance and linked.
                </p>
                <p
                    v-if="fieldError(disp.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(disp.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="dispose-form"
                    :disabled="disp.processing"
                >
                    Draft disposal
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
