<script setup lang="ts">
/**
 * CSR programme (M08): details & outcome, volunteers, in-kind contributions (estimates, never cash — M08-03),
 * cash spend via the linked activity (M08-02), and RESTRICTED evidence photos with consent (M08-04).
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import FileDropzone from '@/Components/Form/FileDropzone.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';
import type { Option } from '@/types/operations';

interface Programme { id: number; programme_no: string; title: string; programme_date: string; partner: string | null; venue: string | null; beneficiary_group: string | null; status: string; activity_id: number | null; objectives: string | null; sponsor: string | null; outcome: string | null; target_count: number; actual_count: number | null }
interface Evidence { id: number; document_id: number; title: string; people_identifiable: boolean; consent_obtained: boolean; consent_note: string | null; publishable: boolean }
const props = defineProps<{
    programme: Programme; activity: { id: number; activity_no: string } | null; summary: { budget: string; cash: string; in_kind: string; volunteer_hours: string; by_kind: Record<string, string> };
    volunteers: { staff_profile_id: number | null; name: string | null; hours: string; role: string | null }[];
    inKind: { id: number; item: string; quantity: string; estimated_value: string; source: string; valuation_basis: string | null }[];
    evidence: Evidence[] | null; staff: Option[]; can: { edit: boolean; evidence: boolean; see_evidence: boolean };
}>();
const tab = ref<'overview' | 'volunteers' | 'in_kind' | 'evidence'>('overview');
const p = props.programme;
const edit = useForm({ title: p.title, programme_date: p.programme_date, objectives: p.objectives ?? '', partner: p.partner ?? '', sponsor: p.sponsor ?? '', venue: p.venue ?? '', beneficiary_group: p.beneficiary_group ?? '',
    target_count: String(p.target_count), status: p.status, actual_count: p.actual_count === null ? '' : String(p.actual_count), outcome: p.outcome ?? '' });
const saveEdit = () => edit.transform((d) => blanksToNull(d, ['actual_count', 'target_count'])).put(route('csr.programmes.update', p.id), { preserveScroll: true });
const vol = useForm({ volunteers: props.volunteers.map((v) => ({ staff_profile_id: (v.staff_profile_id ?? '') as number | '', name: v.name ?? '', hours: v.hours, role: v.role ?? '' })) });
const saveVol = () => vol.transform((d) => ({ volunteers: d.volunteers.map((v) => ({ staff_profile_id: v.staff_profile_id || null, name: v.staff_profile_id ? null : v.name, hours: v.hours || null, role: v.role || null })) })).put(route('csr.programmes.volunteers', p.id), { preserveScroll: true });
const ik = useForm({ item: '', quantity: '1', estimated_value: '', source: '', valuation_basis: '' });
const addIk = () => ik.post(route('csr.programmes.in-kind', p.id), { preserveScroll: true, onSuccess: () => ik.reset() });
const removeIk = (id: number) => { if (window.confirm('Remove this in-kind entry?')) router.delete(route('csr.in-kind.destroy', id), { preserveScroll: true }); };
const ev = useForm<{ file: File | null; people_identifiable: boolean; consent_obtained: boolean; consent_note: string }>({ file: null, people_identifiable: true, consent_obtained: false, consent_note: '' });
const upload = () => ev.post(route('csr.programmes.evidence', p.id), { forceFormData: true, preserveScroll: true, onSuccess: () => ev.reset() });
const consent = (e: Evidence) => { const note = window.prompt('How was consent obtained? (e.g. signed form ref.)'); if (note) router.post(route('csr.evidence.consent', e.id), { consent_note: note }, { preserveScroll: true }); };
const publish = (e: Evidence, v: boolean) => router.post(route('csr.evidence.publishable', e.id), { publishable: v }, { preserveScroll: true });
</script>

<template>
    <AppLayout
        :title="`${programme.programme_no} · ${programme.title}`"
        :breadcrumbs="[{ label: 'Operations' }, { label: 'CSR', href: route('csr.programmes.index') }, { label: programme.programme_no }]"
        :status="programme.status"
    >
        <div class="grid gap-4 sm:grid-cols-4">
            <Card title="Cash spend">
                <p
                    class="text-xl font-semibold"
                    data-test="csr-cash"
                >
                    <Amount :value="summary.cash" />
                </p>
                <p class="text-xs text-surface-500">
                    of budget <Amount :value="summary.budget" />
                </p>
            </Card>
            <Card title="In-kind (estimated)">
                <p
                    class="text-xl font-semibold"
                    data-test="csr-in-kind"
                >
                    <Amount :value="summary.in_kind" />
                </p>
                <p class="text-xs text-surface-500">
                    Not part of cash
                </p>
            </Card>
            <Card title="Volunteer hours">
                <p class="text-xl font-semibold">
                    {{ summary.volunteer_hours }}
                </p>
            </Card>
            <Card title="Finance linkage">
                <Link
                    v-if="activity"
                    :href="route('activities.activities.show', activity.id)"
                    class="text-sm font-medium text-brand-700 hover:underline"
                >
                    {{ activity.activity_no }} — link costs there
                </Link>
            </Card>
        </div>

        <nav
            class="mt-6 flex gap-4 border-b border-surface-200 text-sm"
            role="tablist"
        >
            <button
                v-for="t in (['overview', 'volunteers', 'in_kind', 'evidence'] as const)"
                v-show="t !== 'evidence' || can.see_evidence"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="tab === t"
                :data-test="`tab-${t}`"
                class="-mb-px border-b-2 px-1 py-2 capitalize"
                :class="tab === t ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-surface-500 hover:text-surface-800'"
                @click="tab = t"
            >
                {{ t.replace('_', '-') }}
            </button>
        </nav>

        <section
            v-if="tab === 'overview'"
            class="mt-4"
        >
            <Card>
                <form
                    class="space-y-3"
                    @submit.prevent="saveEdit"
                >
                    <SchemaFields
                        :fields="[{ key: 'title', label: 'Title', type: 'text', span: 2 }, { key: 'programme_date', label: 'Date', type: 'date' }, { key: 'status', label: 'Status', type: 'select', options: ['planned', 'ongoing', 'completed', 'cancelled'].map((s) => ({ value: s, label: s })) },
                                  { key: 'partner', label: 'Partner', type: 'text' }, { key: 'sponsor', label: 'Sponsor', type: 'text' }, { key: 'venue', label: 'Venue', type: 'text' }, { key: 'beneficiary_group', label: 'Beneficiary group', type: 'text' },
                                  { key: 'target_count', label: 'Target', type: 'number' }, { key: 'actual_count', label: 'Reached', type: 'number' }, { key: 'objectives', label: 'Objectives', type: 'textarea' }, { key: 'outcome', label: 'Outcome', type: 'textarea' }]"
                        :values="edit"
                        :errors="edit.errors"
                        :disabled="!can.edit"
                        id-prefix="cp"
                        @set="(k, v) => setField(edit, k, v)"
                    />
                    <Button
                        v-if="can.edit"
                        type="submit"
                        :disabled="edit.processing"
                    >
                        Save
                    </Button>
                </form>
            </Card>
        </section>

        <section
            v-if="tab === 'volunteers'"
            class="mt-4"
        >
            <Card>
                <div
                    v-for="(v, idx) in vol.volunteers"
                    :key="idx"
                    class="mb-2 flex flex-wrap gap-2"
                >
                    <select
                        v-model="v.staff_profile_id"
                        :disabled="!can.edit"
                        :aria-label="`Volunteer ${idx + 1}`"
                        class="rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                        <option value="">
                            External volunteer…
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
                        v-if="!v.staff_profile_id"
                        v-model="v.name"
                        :disabled="!can.edit"
                        placeholder="Name"
                        aria-label="Name"
                        class="rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                    <input
                        v-model="v.hours"
                        :disabled="!can.edit"
                        placeholder="Hours"
                        aria-label="Hours"
                        inputmode="decimal"
                        class="w-20 rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                    <input
                        v-model="v.role"
                        :disabled="!can.edit"
                        placeholder="Role"
                        aria-label="Role"
                        class="rounded border border-surface-300 px-2 py-1 text-sm"
                    >
                    <button
                        v-if="can.edit"
                        type="button"
                        class="text-red-700"
                        aria-label="Remove"
                        @click="vol.volunteers.splice(idx, 1)"
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
                        @click="vol.volunteers.push({ staff_profile_id: '', name: '', hours: '', role: '' })"
                    >
                        Add volunteer
                    </Button>
                    <Button
                        :disabled="vol.processing"
                        @click="saveVol"
                    >
                        Save volunteers
                    </Button>
                </div>
            </Card>
        </section>

        <section
            v-if="tab === 'in_kind'"
            class="mt-4 space-y-4"
        >
            <Card
                title="In-kind contributions"
                description="Goods, services or venues at an estimated value. Kept apart from cash spend in every total and report (M08-03)."
            >
                <table class="w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr>
                            <th>Item</th><th>Qty</th><th>Source</th><th>Valuation basis</th><th class="text-right">
                                Estimated value
                            </th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in inKind"
                            :key="c.id"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1">
                                {{ c.item }}
                            </td>
                            <td>{{ c.quantity }}</td>
                            <td>{{ c.source }}</td>
                            <td>{{ c.valuation_basis ?? '—' }}</td>
                            <td class="text-right font-mono">
                                <Amount :value="c.estimated_value" />
                            </td>
                            <td class="text-right">
                                <button
                                    v-if="can.edit"
                                    type="button"
                                    class="text-xs text-red-700 hover:underline"
                                    @click="removeIk(c.id)"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <Card
                v-if="can.edit"
                title="Add in-kind contribution"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="addIk"
                >
                    <SchemaFields
                        :fields="[{ key: 'item', label: 'Item', type: 'text', required: true }, { key: 'quantity', label: 'Quantity', type: 'text' }, { key: 'estimated_value', label: 'Estimated value (RM)', type: 'money', required: true },
                                  { key: 'source', label: 'Source (who gave it)', type: 'text', required: true }, { key: 'valuation_basis', label: 'Valuation basis', type: 'text', span: 2 }]"
                        :values="ik"
                        :errors="ik.errors"
                        id-prefix="ik"
                        @set="(k, v) => setField(ik, k, v)"
                    />
                    <Button
                        type="submit"
                        data-test="add-in-kind"
                    >
                        Add
                    </Button>
                </form>
            </Card>
        </section>

        <section
            v-if="tab === 'evidence' && evidence"
            class="mt-4 space-y-4"
        >
            <Card
                title="Evidence photos (restricted)"
                description="Stored as restricted documents. Photos showing identifiable people can be marked publishable only after consent is recorded (PDPA)."
            >
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="e in evidence"
                        :key="e.id"
                        class="flex flex-wrap items-center gap-2 py-2"
                    >
                        <a
                            :href="route('documents.download', e.document_id)"
                            class="font-medium text-brand-700 hover:underline"
                        >{{ e.title }}</a>
                        <StatusChip
                            :status="e.people_identifiable ? (e.consent_obtained ? 'approved' : 'pending') : 'clean'"
                            :label="e.people_identifiable ? (e.consent_obtained ? 'consent recorded' : 'no consent') : 'no identifiable people'"
                        />
                        <span
                            v-if="e.consent_note"
                            class="text-xs text-surface-500"
                        >{{ e.consent_note }}</span>
                        <span class="ml-auto flex gap-2">
                            <Button
                                v-if="can.evidence && e.people_identifiable && !e.consent_obtained"
                                variant="secondary"
                                @click="consent(e)"
                            >Record consent</Button>
                            <Button
                                v-if="can.evidence"
                                variant="secondary"
                                :data-test="`publish-${e.id}`"
                                @click="publish(e, !e.publishable)"
                            >{{ e.publishable ? 'Unpublish' : 'Mark publishable' }}</Button>
                        </span>
                    </li>
                    <li
                        v-if="!evidence.length"
                        class="py-2 text-surface-500"
                    >
                        No photos yet.
                    </li>
                </ul>
            </Card>
            <Card
                v-if="can.evidence"
                title="Upload photo"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="upload"
                >
                    <FileDropzone
                        id="csr-photo"
                        v-model="ev.file"
                        label="Photo"
                        :error="ev.errors.file"
                    />
                    <label class="flex items-center gap-2 text-sm"><input
                        v-model="ev.people_identifiable"
                        type="checkbox"
                    > Shows identifiable people</label>
                    <label
                        v-if="ev.people_identifiable"
                        class="flex items-center gap-2 text-sm"
                    ><input
                        v-model="ev.consent_obtained"
                        type="checkbox"
                    > Consent obtained</label>
                    <input
                        v-if="ev.people_identifiable && ev.consent_obtained"
                        v-model="ev.consent_note"
                        placeholder="Consent reference (e.g. signed form no.)"
                        aria-label="Consent reference"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <p
                        v-if="fieldError(ev.errors, 'general')"
                        class="text-sm text-red-700"
                        role="alert"
                    >
                        {{ fieldError(ev.errors, 'general') }}
                    </p>
                    <Button
                        type="submit"
                        :disabled="!ev.file || ev.processing"
                    >
                        Upload (restricted)
                    </Button>
                </form>
            </Card>
        </section>
    </AppLayout>
</template>
