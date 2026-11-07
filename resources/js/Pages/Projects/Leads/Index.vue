<script setup lang="ts">
/**
 * Leads & Pipeline (M02-02, WF-04): stage summary + a proper table of opportunities, and the New Lead form
 * (owner spec 2026-11-07): searchable client with "+ Add New Prospect" / "+ Add New Client", contact details
 * auto-filled from the Client master, opportunity type (Others → specify), source list, next follow-up, lead
 * status matching the pipeline, and a coded lost reason (Others → explanation). Money stays a decimal string.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { sumDecimals } from '@/lib/decimal';
import type { TableColumn } from '@/types/table';

interface Lead {
    id: number; title: string; party: string; client_id: number | null; prospect_name: string | null; stage: string;
    engagement_type: string; engagement_type_other: string | null; type_label: string; estimated_value: string;
    expected_close: string | null; next_follow_up_on: string | null; source: string | null; source_other: string | null;
    contact_person: string | null; contact_email: string | null; contact_phone: string | null; notes: string | null;
    quotation_id: number | null; lost_reason: string | null; lost_reason_code: string | null; owner: string | null;
}
interface ClientOption { id: number; label: string; contact_person: string | null; email: string | null; phone: string | null }
interface Option { value: string; label: string; description?: string }

const props = defineProps<{
    leads: Lead[]; stages: string[]; stageDescriptions: Record<string, string>; types: Option[]; sources: string[];
    lostReasons: Option[]; clients: ClientOption[];
    can: { create: boolean; createClient: boolean; quote: boolean; project: boolean };
}>();

const SUBTITLE = 'This module is used to record and monitor Infernix\'s business opportunities, including potential projects, training programmes, and services. Each opportunity is tracked through different stages: New, Qualified, Proposal, Negotiation, Won, and Lost, enabling effective sales pipeline monitoring, opportunity tracking, and estimated project value management.';
const stageLabel = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
const stageTone = (s: string) => (s === 'won' ? 'success' : s === 'lost' ? 'danger' : s === 'new' ? 'info' : 'warning');
const isOpen = (s: string) => !['won', 'lost'].includes(s);
const fmtDate = (d: string | null) => {
    if (!d) return '—';
    const [y, m, day] = d.split('-');
    return `${Number(day)} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(m) - 1]} ${y}`;
};
const today = new Date().toISOString().slice(0, 10);

// ---- Pipeline summary + table -------------------------------------------------------------------------------
const stageFilter = ref<string | null>(null);
const search = ref('');
const summary = computed(() => props.stages.map((s) => {
    const rows = props.leads.filter((l) => l.stage === s);
    return { stage: s, count: rows.length, total: sumDecimals(rows.map((l) => l.estimated_value)) };
}));
const openPipelineTotal = computed(() => sumDecimals(props.leads.filter((l) => isOpen(l.stage)).map((l) => l.estimated_value)));
const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.leads.filter((l) => (!stageFilter.value || l.stage === stageFilter.value)
        && (q === '' || `${l.title} ${l.party} ${l.contact_person ?? ''} ${l.type_label} ${l.source ?? ''}`.toLowerCase().includes(q)));
});
const columns: TableColumn[] = [
    { key: 'title', label: 'Opportunity', sortable: true, hideable: false },
    { key: 'party', label: 'Client / Prospect', sortable: true },
    { key: 'type_label', label: 'Type', sortable: true },
    { key: 'stage', label: 'Status', sortable: true },
    { key: 'estimated_value', label: 'Estimated value', sortable: true, numeric: true, align: 'right' },
    { key: 'expected_close', label: 'Expected close', sortable: true },
    { key: 'next_follow_up_on', label: 'Next follow-up', sortable: true },
    { key: 'contact_person', label: 'Contact', sortable: true },
    { key: 'source', label: 'Source', sortable: true, hidden: true },
    { key: 'owner', label: 'Created by', sortable: true, hidden: true },
];

// ---- New / edit lead form -----------------------------------------------------------------------------------
const NEW_PROSPECT = 'new_prospect';
const NEW_CLIENT = 'new_client';
const modalOpen = ref(false);
const editing = ref<Lead | null>(null);
const clientPick = ref<number | string | ''>('');
const blank = {
    title: '', prospect_name: '', new_client_name: '', engagement_type: '', engagement_type_other: '',
    estimated_value: '' as string | number | null, expected_close: '', source: '', source_other: '',
    contact_person: '', contact_email: '', contact_phone: '', next_follow_up_on: '', stage: 'new',
    lost_reason_code: '', lost_reason: '', notes: '',
};
const form = useForm({ ...blank });

const clientOptions = computed(() => props.clients.map((c) => ({ value: c.id, label: c.label, meta: c.contact_person })));
const pinnedOptions = computed(() => [
    { value: NEW_PROSPECT, label: '+ Add New Prospect', meta: 'Register a potential client' },
    ...(props.can.createClient ? [{ value: NEW_CLIENT, label: '+ Add New Client', meta: 'Register a new confirmed client' }] : []),
]);
const party = computed<'existing' | 'prospect' | 'new_client' | null>(() => (clientPick.value === NEW_PROSPECT ? 'prospect'
    : clientPick.value === NEW_CLIENT ? 'new_client' : clientPick.value === '' ? null : 'existing'));
const closedLead = computed(() => editing.value !== null && !isOpen(editing.value.stage));
const selectedType = computed(() => props.types.find((t) => t.value === form.engagement_type) ?? null);

// Existing client → fill contact person / email / phone from the Client master (only blanks are filled).
watch(clientPick, (v) => {
    const c = typeof v === 'number' ? props.clients.find((x) => x.id === v) : undefined;
    if (!c) return;
    if (!form.contact_person) form.contact_person = c.contact_person ?? '';
    if (!form.contact_email) form.contact_email = c.email ?? '';
    if (!form.contact_phone) form.contact_phone = c.phone ?? '';
});

function openForm(l: Lead | null) {
    editing.value = l;
    clientPick.value = l ? (l.client_id ?? NEW_PROSPECT) : '';
    form.defaults(l ? {
        ...blank, title: l.title, prospect_name: l.prospect_name ?? '', engagement_type: l.engagement_type,
        engagement_type_other: l.engagement_type_other ?? '', estimated_value: l.estimated_value, expected_close: l.expected_close ?? '',
        source: l.source ?? '', source_other: l.source_other ?? '', contact_person: l.contact_person ?? '', contact_email: l.contact_email ?? '',
        contact_phone: l.contact_phone ?? '', next_follow_up_on: l.next_follow_up_on ?? '', stage: l.stage,
        lost_reason_code: l.lost_reason_code ?? '', lost_reason: l.lost_reason_code === 'other' ? (l.lost_reason ?? '') : '', notes: l.notes ?? '',
    } : { ...blank });
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

const errs = computed(() => form.errors as Record<string, string | undefined>);
const clientError = computed(() => errs.value.party ?? errs.value.client_id ?? errs.value.new_client ?? undefined);
const partyMissing = ref(false);
function save() {
    partyMissing.value = party.value === null;
    if (partyMissing.value) return;
    const changingToLost = form.stage === 'lost' && editing.value?.stage !== 'lost';
    const t = form.transform((d) => ({
        party: party.value,
        client_id: party.value === 'existing' ? clientPick.value : null,
        prospect_name: party.value === 'prospect' ? d.prospect_name : null,
        new_client: party.value === 'new_client' ? { name: d.new_client_name } : null,
        title: d.title, engagement_type: d.engagement_type,
        engagement_type_other: d.engagement_type === 'other' ? d.engagement_type_other : null,
        estimated_value: d.estimated_value === '' || d.estimated_value === null ? null : String(d.estimated_value),
        expected_close: d.expected_close || null, next_follow_up_on: d.next_follow_up_on || null,
        source: d.source || null, source_other: d.source === 'Others' ? d.source_other : null,
        contact_person: d.contact_person || null, contact_email: d.contact_email || null, contact_phone: d.contact_phone || null,
        stage: closedLead.value ? null : d.stage,
        lost_reason_code: changingToLost ? d.lost_reason_code || null : null,
        lost_reason: changingToLost ? d.lost_reason || null : null,
        notes: d.notes || null,
    }));
    const opts = { preserveScroll: true, onSuccess: () => { modalOpen.value = false; } };
    if (editing.value) t.put(route('projects.leads.update', editing.value.id), opts);
    else t.post(route('projects.leads.store'), opts);
}
</script>

<template>
    <AppLayout
        title="Leads & Pipeline"
        :subtitle="SUBTITLE"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Leads & Pipeline' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'projects.pipeline')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Pipeline report
            </Link>
            <Button
                v-if="can.create"
                data-test="add-lead"
                @click="openForm(null)"
            >
                New Lead
            </Button>
        </template>

        <!-- Pipeline summary: one tile per stage; click to filter the table. -->
        <div
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-7"
            role="group"
            aria-label="Filter by status"
        >
            <button
                type="button"
                class="rounded-xl border bg-white p-3 text-left shadow-sm transition hover:border-brand-300"
                :class="stageFilter === null ? 'border-brand-500 ring-2 ring-brand-200' : 'border-surface-200'"
                :aria-pressed="stageFilter === null"
                data-test="stage-all"
                @click="stageFilter = null"
            >
                <span class="text-xs font-medium uppercase tracking-wide text-surface-500">All leads</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums text-surface-900">{{ leads.length }}</span>
                <span class="block text-xs text-surface-500">Open pipeline <Amount :value="openPipelineTotal" /></span>
            </button>
            <button
                v-for="s in summary"
                :key="s.stage"
                type="button"
                class="rounded-xl border bg-white p-3 text-left shadow-sm transition hover:border-brand-300"
                :class="stageFilter === s.stage ? 'border-brand-500 ring-2 ring-brand-200' : 'border-surface-200'"
                :aria-pressed="stageFilter === s.stage"
                :title="stageDescriptions[s.stage]"
                :data-test="`stage-${s.stage}`"
                @click="stageFilter = stageFilter === s.stage ? null : s.stage"
            >
                <StatusChip
                    :status="s.stage"
                    :label="stageLabel(s.stage)"
                    :tone="stageTone(s.stage)"
                />
                <span class="mt-1 block text-2xl font-bold tabular-nums text-surface-900">{{ s.count }}</span>
                <span class="block text-xs text-surface-500"><Amount :value="s.total" /></span>
            </button>
        </div>

        <div class="mt-5">
            <DataTable
                :columns="columns"
                :rows="rows"
                prefs-key="projects.leads"
                caption="Leads"
                :empty-text="leads.length === 0 ? 'No leads yet. Click “New Lead” to record your first opportunity.' : 'No leads match this filter.'"
            >
                <template #toolbar>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search opportunity, client, contact…"
                        aria-label="Search leads"
                        class="w-64 max-w-full"
                    >
                </template>
                <template #cell-title="{ row }">
                    <button
                        type="button"
                        class="text-left font-medium text-brand-700 hover:underline"
                        :data-test="`lead-${row.id}`"
                        @click="openForm(row as Lead)"
                    >
                        {{ row.title }}
                    </button>
                    <span
                        v-if="row.lost_reason"
                        class="block text-xs text-red-700"
                    >{{ row.lost_reason }}</span>
                </template>
                <template #cell-stage="{ row }">
                    <StatusChip
                        :status="String(row.stage)"
                        :label="stageLabel(String(row.stage))"
                        :tone="stageTone(String(row.stage))"
                    />
                </template>
                <template #cell-estimated_value="{ row }">
                    <Amount :value="String(row.estimated_value)" />
                </template>
                <template #cell-expected_close="{ row }">
                    {{ fmtDate(row.expected_close as string | null) }}
                </template>
                <template #cell-next_follow_up_on="{ row }">
                    <span :class="row.next_follow_up_on && isOpen(String(row.stage)) && String(row.next_follow_up_on) < today ? 'font-medium text-red-700' : ''">
                        {{ fmtDate(row.next_follow_up_on as string | null) }}
                    </span>
                </template>
                <template #cell-contact_person="{ row }">
                    <span v-if="row.contact_person">{{ row.contact_person }}</span>
                    <span
                        v-if="row.contact_phone || row.contact_email"
                        class="block whitespace-nowrap text-xs text-surface-500"
                    >{{ row.contact_phone || row.contact_email }}</span>
                    <span v-if="!row.contact_person && !row.contact_phone && !row.contact_email">—</span>
                </template>
                <template #cell-source="{ row }">
                    {{ row.source === 'Others' && row.source_other ? row.source_other : (row.source ?? '—') }}
                </template>
                <template #actions="{ row }">
                    <span class="inline-flex gap-2">
                        <Link
                            v-if="row.quotation_id"
                            :href="route('commercial.quotations.show', Number(row.quotation_id))"
                            class="rounded-md bg-brand-50 px-2 py-1 text-xs font-medium text-brand-800"
                        >
                            Quotation
                        </Link>
                        <Link
                            v-else-if="can.quote && isOpen(String(row.stage)) && row.client_id"
                            :href="route('commercial.quotations.create', { lead_id: Number(row.id) })"
                            class="rounded-md bg-brand-600 px-2 py-1 text-xs font-medium text-white"
                            :data-test="`quote-${row.id}`"
                        >
                            Quote
                        </Link>
                        <button
                            type="button"
                            class="rounded-md border border-surface-200 px-2 py-1 text-xs font-medium hover:bg-surface-50"
                            @click="openForm(row as Lead)"
                        >
                            Edit
                        </button>
                    </span>
                </template>
            </DataTable>
        </div>

        <Modal
            :open="modalOpen"
            :title="editing ? `Edit lead — ${editing.title}` : 'New Lead'"
            size="lg"
            @close="modalOpen = false"
        >
            <p class="-mt-1 mb-4 text-sm text-surface-500">
                Fill in the details below to {{ editing ? 'update this' : 'record a new' }} business opportunity.
            </p>
            <form
                id="lead-form"
                class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2"
                novalidate
                @submit.prevent="save"
            >
                <FormField
                    id="l-title"
                    label="Opportunity Name"
                    required
                    :error="form.errors.title"
                    class="sm:col-span-2"
                >
                    <input
                        id="l-title"
                        v-model="form.title"
                        data-autofocus
                        required
                        placeholder="e.g. Power BI Training for KEMAS"
                        class="w-full"
                    >
                </FormField>

                <FormField
                    id="l-client"
                    label="Client"
                    required
                    :error="partyMissing ? 'Choose a client, or add a new prospect / client.' : clientError"
                    :hint="party === 'existing' ? 'Contact details are filled in from the Client master.' : undefined"
                >
                    <SearchSelect
                        id="l-client"
                        v-model="clientPick"
                        :options="clientOptions"
                        :pinned="pinnedOptions"
                        placeholder="Select existing client or add new"
                        :invalid="partyMissing || !!clientError"
                        @update:model-value="partyMissing = false"
                    />
                </FormField>
                <FormField
                    v-if="party === 'prospect'"
                    id="l-prospect"
                    label="Prospect Name"
                    required
                    :error="form.errors.prospect_name"
                >
                    <input
                        id="l-prospect"
                        v-model="form.prospect_name"
                        placeholder="e.g. ABC Sdn Bhd"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    v-else-if="party === 'new_client'"
                    id="l-newclient"
                    label="New Client Name"
                    required
                    :error="errs['new_client.name']"
                    hint="Saved to the Client master as an active client."
                >
                    <input
                        id="l-newclient"
                        v-model="form.new_client_name"
                        placeholder="e.g. ABC Sdn Bhd"
                        class="w-full"
                    >
                </FormField>
                <div
                    v-else
                    class="hidden sm:block"
                />

                <FormField
                    id="l-type"
                    label="Type"
                    required
                    :error="form.errors.engagement_type"
                    :hint="selectedType?.description"
                >
                    <select
                        id="l-type"
                        v-model="form.engagement_type"
                        class="w-full"
                    >
                        <option
                            value=""
                            disabled
                        >
                            Select type
                        </option>
                        <option
                            v-for="t in types"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="l-value"
                    label="Estimated Value"
                    required
                    :error="form.errors.estimated_value"
                >
                    <MoneyInput
                        id="l-value"
                        v-model="form.estimated_value"
                    />
                </FormField>
                <FormField
                    v-if="form.engagement_type === 'other'"
                    id="l-type-other"
                    label="Specify Opportunity Type"
                    required
                    :error="form.errors.engagement_type_other"
                    class="sm:col-span-2"
                >
                    <input
                        id="l-type-other"
                        v-model="form.engagement_type_other"
                        maxlength="120"
                        placeholder="e.g. Event management"
                        class="w-full"
                    >
                </FormField>

                <FormField
                    id="l-close"
                    label="Expected Close"
                    :error="form.errors.expected_close"
                >
                    <input
                        id="l-close"
                        v-model="form.expected_close"
                        type="date"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="l-source"
                    label="Source"
                    :error="form.errors.source"
                >
                    <select
                        id="l-source"
                        v-model="form.source"
                        class="w-full"
                    >
                        <option value="">
                            Select source
                        </option>
                        <option
                            v-for="s in sources"
                            :key="s"
                            :value="s"
                        >
                            {{ s }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="form.source === 'Others'"
                    id="l-source-other"
                    label="Specify Source"
                    :error="form.errors.source_other"
                    class="sm:col-span-2"
                >
                    <input
                        id="l-source-other"
                        v-model="form.source_other"
                        maxlength="120"
                        class="w-full"
                    >
                </FormField>

                <FormField
                    id="l-contact"
                    label="Contact Person"
                    :error="form.errors.contact_person"
                >
                    <input
                        id="l-contact"
                        v-model="form.contact_person"
                        placeholder="e.g. Nurul Aisyah"
                        autocomplete="off"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="l-email"
                    label="Contact Email"
                    :error="form.errors.contact_email"
                >
                    <input
                        id="l-email"
                        v-model="form.contact_email"
                        type="email"
                        placeholder="e.g. contact@company.com"
                        autocomplete="off"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="l-phone"
                    label="Contact Number"
                    :error="form.errors.contact_phone"
                >
                    <input
                        id="l-phone"
                        v-model="form.contact_phone"
                        type="tel"
                        placeholder="e.g. +60 12-345 6789"
                        autocomplete="off"
                        class="w-full"
                    >
                </FormField>
                <FormField
                    id="l-followup"
                    label="Next Follow-up Date"
                    :error="form.errors.next_follow_up_on"
                >
                    <input
                        id="l-followup"
                        v-model="form.next_follow_up_on"
                        type="date"
                        class="w-full"
                    >
                </FormField>

                <FormField
                    id="l-stage"
                    label="Lead Status"
                    :error="form.errors.stage"
                    :hint="closedLead ? 'This lead is closed; its status can no longer change.' : stageDescriptions[form.stage]"
                >
                    <select
                        id="l-stage"
                        v-model="form.stage"
                        :disabled="closedLead"
                        class="w-full"
                    >
                        <option
                            v-for="s in stages"
                            :key="s"
                            :value="s"
                        >
                            {{ stageLabel(s) }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="form.stage === 'lost' && !closedLead"
                    id="l-lost"
                    label="Lost Reason"
                    required
                    :error="form.errors.lost_reason_code"
                >
                    <select
                        id="l-lost"
                        v-model="form.lost_reason_code"
                        class="w-full"
                    >
                        <option
                            value=""
                            disabled
                        >
                            Select reason
                        </option>
                        <option
                            v-for="r in lostReasons"
                            :key="r.value"
                            :value="r.value"
                        >
                            {{ r.label }}
                        </option>
                    </select>
                </FormField>
                <div
                    v-else-if="closedLead && editing?.lost_reason"
                    class="text-sm"
                >
                    <span class="mb-1 block font-medium">Lost Reason</span>
                    <span class="text-red-700">{{ editing.lost_reason }}</span>
                </div>
                <FormField
                    v-if="form.stage === 'lost' && !closedLead && form.lost_reason_code === 'other'"
                    id="l-lost-other"
                    label="Explain why the lead was lost"
                    required
                    :error="form.errors.lost_reason"
                    class="sm:col-span-2"
                >
                    <textarea
                        id="l-lost-other"
                        v-model="form.lost_reason"
                        rows="2"
                        maxlength="500"
                        class="w-full"
                    />
                </FormField>

                <FormField
                    id="l-notes"
                    label="Notes"
                    :error="form.errors.notes"
                    class="sm:col-span-2"
                >
                    <textarea
                        id="l-notes"
                        v-model="form.notes"
                        rows="3"
                        placeholder="Add any additional details or notes…"
                        class="w-full"
                    />
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="modalOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="lead-form"
                    :disabled="form.processing"
                    data-test="save-lead"
                >
                    Save Lead
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
