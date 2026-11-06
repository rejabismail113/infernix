<script setup lang="ts">
/**
 * Activity / trip (M07): itinerary (estimates), attendees, LINKED costs from Finance with budget vs actual
 * (nothing copied — M07-02), post-event report and photos (M07-03).
 */
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { sumDecimals } from '@/lib/decimal';
import { fieldError } from '@/lib/errors';
import { blanksToNull, enumOptions, setField } from '@/lib/forms';
import type { LinkedDoc } from '@/types/finance';
import type { Option } from '@/types/operations';

interface Activity { id: number; activity_no: string; type: string; title: string; start_date: string; end_date: string; location: string | null; status: string; budget: string; purpose: string | null; project_id: number | null; client_id: number | null; organizer_id: number | null; report: string | null; report_submitted_at: string | null; project: string | null; client: string | null }
interface Cost { line_type: string; line_id: number; source_type: string; source_id: number; number: string; date: string; description: string; kind: string; amount: string; project_id: number | null; url: string }
interface Candidate { line_type: string; line_id: number; number: string; date: string; description: string; amount: string; activity_id: number | null }
const props = defineProps<{
    activity: Activity; attendees: { staff_profile_id: number | null; name: string | null; role: string | null }[];
    itinerary: { item_date: string; kind: string; description: string; estimated_cost: string }[];
    costs: Cost[]; summary: { budget: string; actual: string; variance: string; by_kind: Record<string, string> }; documents: LinkedDoc[];
    staff: Option[]; projects: Option[]; clients: Option[]; types: string[]; can: { edit: boolean; link_costs: boolean };
}>();
const tab = ref<'overview' | 'itinerary' | 'attendees' | 'costs' | 'report'>('overview');
const KINDS = ['travel', 'mileage', 'hotel', 'meal', 'transport', 'session', 'other'];

const editOpen = ref(false);
const edit = useForm({ type: props.activity.type, title: props.activity.title, start_date: props.activity.start_date, end_date: props.activity.end_date, location: props.activity.location ?? '', purpose: props.activity.purpose ?? '',
    project_id: (props.activity.project_id ?? '') as number | '', client_id: (props.activity.client_id ?? '') as number | '', budget: props.activity.budget });
const saveEdit = () => edit.transform((d) => blanksToNull(d, ['project_id', 'client_id'])).put(route('activities.activities.update', props.activity.id), { onSuccess: () => { editOpen.value = false; } });
const setStatus = (status: string) => router.post(route('activities.activities.status', props.activity.id), { status }, { preserveScroll: true });

const itin = useForm({ items: props.itinerary.map((i) => ({ ...i })) });
watch(() => props.itinerary, (v) => { itin.items = v.map((i) => ({ ...i })); });
const estimated = computed(() => sumDecimals(itin.items.map((i) => i.estimated_cost || '0')));
const saveItin = () => itin.put(route('activities.activities.itinerary', props.activity.id), { preserveScroll: true });

const att = useForm({ attendees: props.attendees.map((a) => ({ staff_profile_id: (a.staff_profile_id ?? '') as number | '', name: a.name ?? '', role: a.role ?? '' })) });
const saveAtt = () => att.transform((d) => ({ attendees: d.attendees.map((a) => ({ staff_profile_id: a.staff_profile_id || null, name: a.staff_profile_id ? null : a.name, role: a.role || null })) })).put(route('activities.activities.attendees', props.activity.id), { preserveScroll: true });

const report = useForm({ report: props.activity.report ?? '' });
const saveReport = () => report.put(route('activities.activities.report', props.activity.id), { preserveScroll: true });

const q = ref('');
const candidates = ref<Candidate[]>([]);
const searchError = ref<string | null>(null);
async function searchCosts() {
    searchError.value = null;
    const res = await fetch(route('activities.activities.cost-candidates', { activity: props.activity.id, q: q.value }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) { searchError.value = 'Search failed.'; return; }
    candidates.value = ((await res.json()) as { lines: Candidate[] }).lines;
}
const linkCost = (c: Candidate) => router.post(route('activities.activities.costs.link', props.activity.id), { line_type: c.line_type, line_id: c.line_id }, { preserveScroll: true, onSuccess: () => { candidates.value = []; } });
const unlinkCost = (c: Cost) => router.delete(route('activities.activities.costs.unlink', props.activity.id), { data: { line_type: c.line_type, line_id: c.line_id }, preserveScroll: true });
</script>

<template>
    <AppLayout
        :title="`${activity.activity_no} · ${activity.title}`"
        :breadcrumbs="[{ label: 'Operations' }, { label: 'Activities', href: route('activities.activities.index') }, { label: activity.activity_no }]"
        :status="activity.status"
    >
        <template #actions>
            <template v-if="can.edit">
                <Button
                    v-if="activity.status === 'planned'"
                    variant="secondary"
                    @click="setStatus('ongoing')"
                >
                    Start
                </Button>
                <Button
                    v-if="['planned', 'ongoing'].includes(activity.status)"
                    variant="secondary"
                    @click="setStatus('completed')"
                >
                    Complete
                </Button>
                <Button
                    variant="secondary"
                    @click="editOpen = true"
                >
                    Edit
                </Button>
            </template>
        </template>

        <div class="grid gap-4 sm:grid-cols-4">
            <Card title="Budget">
                <p class="text-xl font-semibold">
                    <Amount :value="summary.budget" />
                </p>
            </Card>
            <Card title="Actual (linked)">
                <p
                    class="text-xl font-semibold"
                    data-test="activity-actual"
                >
                    <Amount :value="summary.actual" />
                </p>
            </Card>
            <Card title="Variance">
                <p class="text-xl font-semibold">
                    <Amount :value="summary.variance" />
                </p>
            </Card>
            <Card title="Linked to">
                <p class="text-sm">
                    {{ activity.project ?? 'No project' }}<br><span class="text-surface-500">{{ activity.client ?? '' }}</span>
                </p>
            </Card>
        </div>

        <nav
            class="mt-6 flex gap-4 border-b border-surface-200 text-sm"
            role="tablist"
        >
            <button
                v-for="t in (['overview', 'itinerary', 'attendees', 'costs', 'report'] as const)"
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
            v-if="tab === 'overview'"
            class="mt-4"
        >
            <Card>
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-surface-500">
                            Type
                        </dt><dd>{{ activity.type.replace('_', ' ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Dates
                        </dt><dd>{{ activity.start_date }} – {{ activity.end_date }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Location
                        </dt><dd>{{ activity.location ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Status
                        </dt><dd><StatusChip :status="activity.status" /></dd>
                    </div>
                </dl>
                <p
                    v-if="activity.purpose"
                    class="mt-3 whitespace-pre-line text-sm"
                >
                    {{ activity.purpose }}
                </p>
                <div
                    v-if="Object.keys(summary.by_kind).length"
                    class="mt-4"
                >
                    <h3 class="text-sm font-medium">
                        Actual by kind
                    </h3>
                    <ul class="mt-1 flex flex-wrap gap-3 text-sm">
                        <li
                            v-for="(v, k) in summary.by_kind"
                            :key="k"
                        >
                            <span class="text-surface-500">{{ String(k).replace('_', ' ') }}</span> <Amount :value="v" />
                        </li>
                    </ul>
                </div>
            </Card>
        </section>

        <section
            v-if="tab === 'itinerary'"
            class="mt-4"
        >
            <Card :description="`Estimates only (planning). Estimated total RM ${estimated}.`">
                <table class="w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr>
                            <th>Date</th><th>Kind</th><th>Description</th><th class="text-right">
                                Estimate
                            </th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(i, idx) in itin.items"
                            :key="idx"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1">
                                <input
                                    v-model="i.item_date"
                                    type="date"
                                    :disabled="!can.edit"
                                    :aria-label="`Date ${idx + 1}`"
                                    class="rounded border border-surface-300 px-2 py-1"
                                >
                            </td>
                            <td>
                                <select
                                    v-model="i.kind"
                                    :disabled="!can.edit"
                                    :aria-label="`Kind ${idx + 1}`"
                                    class="rounded border border-surface-300 px-2 py-1"
                                >
                                    <option
                                        v-for="k in KINDS"
                                        :key="k"
                                        :value="k"
                                    >
                                        {{ k }}
                                    </option>
                                </select>
                            </td>
                            <td>
                                <input
                                    v-model="i.description"
                                    :disabled="!can.edit"
                                    :aria-label="`Description ${idx + 1}`"
                                    class="w-full rounded border border-surface-300 px-2 py-1"
                                >
                            </td>
                            <td class="w-36">
                                <MoneyInput
                                    v-model="i.estimated_cost"
                                    :disabled="!can.edit"
                                />
                            </td>
                            <td>
                                <button
                                    v-if="can.edit"
                                    type="button"
                                    class="text-red-700"
                                    aria-label="Remove"
                                    @click="itin.items.splice(idx, 1)"
                                >
                                    ×
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="Object.keys(itin.errors).length"
                    class="mt-2 text-sm text-red-700"
                    role="alert"
                >
                    {{ Object.values(itin.errors)[0] }}
                </p>
                <div
                    v-if="can.edit"
                    class="mt-3 flex gap-2"
                >
                    <Button
                        variant="secondary"
                        @click="itin.items.push({ item_date: activity.start_date, kind: 'travel', description: '', estimated_cost: '' })"
                    >
                        Add line
                    </Button>
                    <Button
                        :disabled="itin.processing"
                        @click="saveItin"
                    >
                        Save itinerary
                    </Button>
                </div>
            </Card>
        </section>

        <section
            v-if="tab === 'attendees'"
            class="mt-4"
        >
            <Card>
                <div
                    v-for="(a, idx) in att.attendees"
                    :key="idx"
                    class="mb-2 flex flex-wrap gap-2"
                >
                    <select
                        v-model="a.staff_profile_id"
                        :disabled="!can.edit"
                        :aria-label="`Attendee ${idx + 1}`"
                        class="rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                        <option value="">
                            External guest…
                        </option>
                        <option
                            v-for="s in staff"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                    <input
                        v-if="!a.staff_profile_id"
                        v-model="a.name"
                        :disabled="!can.edit"
                        placeholder="Name"
                        aria-label="Guest name"
                        class="rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                    <input
                        v-model="a.role"
                        :disabled="!can.edit"
                        placeholder="Role"
                        aria-label="Role"
                        class="rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                    <button
                        v-if="can.edit"
                        type="button"
                        class="text-red-700"
                        aria-label="Remove attendee"
                        @click="att.attendees.splice(idx, 1)"
                    >
                        ×
                    </button>
                </div>
                <div
                    v-if="can.edit"
                    class="flex gap-2"
                >
                    <Button
                        variant="secondary"
                        @click="att.attendees.push({ staff_profile_id: '', name: '', role: '' })"
                    >
                        Add attendee
                    </Button>
                    <Button
                        :disabled="att.processing"
                        @click="saveAtt"
                    >
                        Save attendees
                    </Button>
                </div>
                <p
                    v-if="!att.attendees.length && !can.edit"
                    class="text-sm text-surface-500"
                >
                    No attendees.
                </p>
            </Card>
        </section>

        <section
            v-if="tab === 'costs'"
            class="mt-4 space-y-4"
        >
            <Card
                title="Linked costs"
                description="Booked claim lines (mileage, hotels, meals…), expenses and vendor bills tagged to this activity. They are counted once — in Finance and, via their project tag, in project margin."
            >
                <table class="w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr>
                            <th>Record</th><th>Date</th><th>Kind</th><th>Description</th><th class="text-right">
                                Amount
                            </th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in costs"
                            :key="`${c.line_type}-${c.line_id}`"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1">
                                <a
                                    :href="c.url"
                                    class="text-brand-700 hover:underline"
                                >{{ c.number }}</a>
                            </td>
                            <td>{{ c.date }}</td>
                            <td>{{ c.kind.replace('_', ' ') }}</td>
                            <td>{{ c.description }}</td>
                            <td class="text-right font-mono">
                                <Amount :value="c.amount" />
                            </td>
                            <td class="text-right">
                                <button
                                    v-if="can.link_costs"
                                    type="button"
                                    class="text-xs text-red-700 hover:underline"
                                    @click="unlinkCost(c)"
                                >
                                    Unlink
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!costs.length">
                            <td
                                colspan="6"
                                class="py-2 text-surface-500"
                            >
                                No costs linked yet.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-surface-200 font-medium">
                            <td colspan="4">
                                Total
                            </td>
                            <td class="text-right font-mono">
                                <Amount :value="summary.actual" />
                            </td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </Card>
            <Card
                v-if="can.link_costs"
                title="Link a booked cost"
            >
                <form
                    class="flex gap-2"
                    @submit.prevent="searchCosts"
                >
                    <input
                        v-model="q"
                        type="search"
                        placeholder="Claim / expense / bill number or description"
                        aria-label="Search costs"
                        class="flex-1 rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Search
                    </Button>
                </form>
                <p
                    v-if="searchError"
                    class="mt-2 text-sm text-red-700"
                >
                    {{ searchError }}
                </p>
                <ul class="mt-2 divide-y divide-surface-100 text-sm">
                    <li
                        v-for="c in candidates"
                        :key="`${c.line_type}-${c.line_id}`"
                        class="flex items-center gap-2 py-1"
                    >
                        <span class="font-medium">{{ c.number }}</span>
                        <span class="text-surface-500">{{ c.date }} · {{ c.description }}</span>
                        <span class="ml-auto font-mono"><Amount :value="c.amount" /></span>
                        <span
                            v-if="c.activity_id && c.activity_id !== activity.id"
                            class="text-xs text-amber-700"
                        >linked elsewhere</span>
                        <Button
                            v-else-if="c.activity_id !== activity.id"
                            variant="secondary"
                            :data-test="`link-cost-${c.line_type}-${c.line_id}`"
                            @click="linkCost(c)"
                        >
                            Link
                        </Button>
                    </li>
                </ul>
            </Card>
        </section>

        <section
            v-if="tab === 'report'"
            class="mt-4 grid gap-4 lg:grid-cols-2"
        >
            <Card
                title="Post-event report"
                :description="activity.report_submitted_at ? `Last saved ${activity.report_submitted_at}` : undefined"
            >
                <textarea
                    v-model="report.report"
                    rows="10"
                    :disabled="!can.edit"
                    aria-label="Report"
                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                />
                <p
                    v-if="fieldError(report.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(report.errors, 'general') }}
                </p>
                <Button
                    v-if="can.edit"
                    class="mt-2"
                    :disabled="report.processing"
                    @click="saveReport"
                >
                    Save report
                </Button>
            </Card>
            <Card title="Photos & documents">
                <EvidencePanel
                    entity-type="activity"
                    :entity-id="activity.id"
                    link-type="photo"
                    :documents="documents"
                    :can-attach="can.edit"
                />
            </Card>
        </section>

        <Modal
            :open="editOpen"
            title="Edit activity"
            @close="editOpen = false"
        >
            <form
                id="edit-activity"
                @submit.prevent="saveEdit"
            >
                <SchemaFields
                    :fields="[{ key: 'type', label: 'Type', type: 'select', options: enumOptions(types) }, { key: 'title', label: 'Title', type: 'text' }, { key: 'start_date', label: 'Start', type: 'date' }, { key: 'end_date', label: 'End', type: 'date' },
                              { key: 'location', label: 'Location', type: 'text' }, { key: 'budget', label: 'Budget (RM)', type: 'money' }, { key: 'project_id', label: 'Project', type: 'select', options: projects.map((p) => ({ value: p.id, label: p.label })) },
                              { key: 'client_id', label: 'Client', type: 'select', options: clients.map((c) => ({ value: c.id, label: c.label })) }, { key: 'purpose', label: 'Purpose', type: 'textarea' }]"
                    :values="edit"
                    :errors="edit.errors"
                    id-prefix="ea"
                    @set="(k, v) => setField(edit, k, v)"
                />
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="edit-activity"
                    :disabled="edit.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
