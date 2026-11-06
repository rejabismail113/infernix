<script setup lang="ts">
/**
 * Project profile (M02): delivery status separate from billing status (M02-04), milestones / tasks / blockers
 * (M02-03), team with rate version (M02-07, WF-05), training sessions (M02-06), documents, closeout checklist.
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import type { LinkedDoc } from '@/types/finance';

interface Task { id: number; title: string; status: string; due_on: string | null; assignee_staff_id: number | null; assignee: string | null }
interface Milestone { id: number; title: string; deliverable: string | null; due_on: string | null; status: string; blocker: string | null; billable_amount: string | null; overdue: boolean; completed_at: string | null; tasks: Task[] }
interface Member { id: number; staff_profile_id: number; name: string | null; role: string; from_date: string; to_date: string | null; engagement_id: number | null }
interface Session { id: number; course_title: string; session_date: string; venue: string | null; status: string; trainer: string | null; participants: number; feedback_score: string | null }
interface Project {
    id: number; code: string; name: string; client: string; client_id: number; engagement_type: string; contract_value: string; status_delivery: string; status_payment: string;
    source_quotation_id: number | null; scope: string | null; description: string | null; billing_terms: string | null; starts_on: string | null; ends_on: string | null;
    cost_budget: string | null; pm_user_id: number | null; pm: string | null; closeout: Record<string, boolean>; lead_id: number | null;
}
const props = defineProps<{
    project: Project; closeoutItems: Record<string, string>; members: Member[]; milestones: Milestone[]; sessions: Session[]; documents: LinkedDoc[];
    staffOptions: { id: number; label: string }[]; pmOptions: { id: number; label: string }[];
    can: { edit: boolean; invoice: boolean; quotation: boolean; financials: boolean; timesheet: boolean };
}>();
const tab = ref<'milestones' | 'team' | 'training' | 'documents' | 'closeout'>('milestones');
const p = props.project;
const editOpen = ref(false);
const edit = useForm({ client_id: p.client_id, name: p.name, engagement_type: p.engagement_type, scope: p.scope ?? '', description: p.description ?? '', starts_on: p.starts_on ?? '', ends_on: p.ends_on ?? '',
    contract_value: p.contract_value as string | number | null, cost_budget: (p.cost_budget ?? '') as string | number | null, billing_terms: p.billing_terms ?? '', pm_user_id: (p.pm_user_id ?? '') as number | '' });
const saveProject = () => edit.transform((d) => ({ ...d, contract_value: String(d.contract_value ?? '0'), cost_budget: d.cost_budget === '' || d.cost_budget === null ? null : String(d.cost_budget), pm_user_id: d.pm_user_id || null, starts_on: d.starts_on || null, ends_on: d.ends_on || null }))
    .put(route('projects.projects.update', p.id), { preserveScroll: true, onSuccess: () => { editOpen.value = false; } });
const nextStatuses: Record<string, string[]> = { lead: ['active', 'closed'], active: ['on_hold', 'completed'], on_hold: ['active', 'completed'], completed: ['active', 'closed'] };
const setStatus = (s: string) => router.post(route('projects.projects.status', p.id), { status: s }, { preserveScroll: true });

const msOpen = ref(false);
const msEditing = ref<Milestone | null>(null);
const ms = useForm({ title: '', deliverable: '', due_on: '', status: 'pending', blocker: '', billable_amount: '' as string | number | null });
function openMilestone(m: Milestone | null) {
    msEditing.value = m;
    ms.defaults(m ? { title: m.title, deliverable: m.deliverable ?? '', due_on: m.due_on ?? '', status: m.status, blocker: m.blocker ?? '', billable_amount: m.billable_amount ?? '' } : { title: '', deliverable: '', due_on: '', status: 'pending', blocker: '', billable_amount: '' });
    ms.reset(); ms.clearErrors(); msOpen.value = true;
}
function saveMilestone() {
    const t = ms.transform((d) => ({ ...d, due_on: d.due_on || null, billable_amount: d.billable_amount === '' || d.billable_amount === null ? null : String(d.billable_amount) }));
    const opts = { preserveScroll: true, onSuccess: () => { msOpen.value = false; } };
    if (msEditing.value) t.put(route('projects.milestones.update', msEditing.value.id), opts); else t.post(route('projects.milestones.store', p.id), opts);
}
const task = useForm({ milestone_id: 0, title: '', assignee_staff_id: '' as number | '', due_on: '', status: 'todo' });
function addTask(m: Milestone) {
    if (!task.title) return;
    task.transform((d) => ({ title: d.title, assignee_staff_id: d.assignee_staff_id || null, due_on: d.due_on || null, status: d.status })).post(route('projects.tasks.store', m.id), { preserveScroll: true, onSuccess: () => task.reset() });
}
const taskStatus = (t: Task, status: string) => router.put(route('projects.tasks.update', t.id), { title: t.title, assignee_staff_id: t.assignee_staff_id, due_on: t.due_on, status }, { preserveScroll: true });

const member = useForm({ staff_profile_id: '' as number | '', role: 'member', from_date: new Date().toISOString().slice(0, 10), to_date: '' });
const addMember = () => member.transform((d) => ({ ...d, to_date: d.to_date || null })).post(route('projects.members.store', p.id), { preserveScroll: true, onSuccess: () => member.reset('staff_profile_id') });
const endMember = (m: Member) => { const d = window.prompt('Assignment ends on (YYYY-MM-DD)', new Date().toISOString().slice(0, 10)); if (d) router.post(route('projects.members.end', m.id), { to_date: d }, { preserveScroll: true }); };

const session = useForm({ course_title: p.name, session_date: '', venue: '', trainer_staff_id: '' as number | '' });
const addSession = () => session.transform((d) => ({ ...d, trainer_staff_id: d.trainer_staff_id || null })).post(route('projects.sessions.store', p.id));

const closeout = useForm({ items: { ...Object.fromEntries(Object.keys(props.closeoutItems).map((k) => [k, !!p.closeout[k]])) } as Record<string, boolean> });
</script>

<template>
    <AppLayout
        :title="`${project.code} ${project.name}`"
        :status="project.status_delivery"
        :breadcrumbs="[{ label: 'Projects', href: route('projects.projects.index') }, { label: project.code }]"
    >
        <template #chips>
            <StatusChip
                :status="project.status_payment === 'paid' ? 'paid' : project.status_payment === 'unbilled' ? 'draft' : 'pending'"
                :label="`billing: ${project.status_payment.replace('_', ' ')}`"
            />
        </template>
        <template #actions>
            <Link
                v-if="can.financials"
                :href="route('reports.projects.financials', project.id)"
                class="rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
                data-test="financials"
            >
                Financials & document chain
            </Link>
            <Link
                v-if="can.invoice"
                :href="route('commercial.invoices.create', { project_id: project.id })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                New invoice
            </Link>
            <Button
                v-if="can.edit"
                variant="secondary"
                @click="editOpen = true"
            >
                Edit
            </Button>
            <select
                v-if="can.edit && nextStatuses[project.status_delivery]"
                aria-label="Change delivery status"
                class="rounded-md border border-surface-300 px-2 py-2 text-sm"
                value=""
                @change="setStatus(($event.target as HTMLSelectElement).value)"
            >
                <option
                    value=""
                    disabled
                >
                    Delivery status…
                </option>
                <option
                    v-for="s in nextStatuses[project.status_delivery]"
                    :key="s"
                    :value="s"
                >
                    → {{ s.replace('_', ' ') }}
                </option>
            </select>
        </template>

        <Card>
            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-surface-500">
                        Client
                    </dt><dd class="font-medium">
                        {{ project.client }}
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Type · PM
                    </dt><dd>{{ project.engagement_type }} · {{ project.pm ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Contract value
                    </dt><dd class="text-lg font-semibold">
                        <Amount :value="project.contract_value" />
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Dates
                    </dt><dd>{{ project.starts_on ?? '—' }} → {{ project.ends_on ?? '—' }}</dd>
                </div>
                <div v-if="project.billing_terms">
                    <dt class="text-surface-500">
                        Billing terms
                    </dt><dd>{{ project.billing_terms }}</dd>
                </div>
                <div v-if="project.cost_budget">
                    <dt class="text-surface-500">
                        Cost budget
                    </dt><dd><Amount :value="project.cost_budget" /></dd>
                </div>
                <div v-if="project.source_quotation_id && can.quotation">
                    <dt class="text-surface-500">
                        Source quotation
                    </dt><dd>
                        <Link
                            :href="route('commercial.quotations.show', project.source_quotation_id)"
                            class="text-brand-700 hover:underline"
                        >
                            Open
                        </Link>
                    </dd>
                </div>
            </dl>
            <p
                v-if="project.scope"
                class="mt-3 whitespace-pre-line text-sm text-surface-700"
            >
                {{ project.scope }}
            </p>
        </Card>

        <nav
            class="flex gap-1 border-b border-surface-200 text-sm"
            role="tablist"
        >
            <button
                v-for="t in (['milestones', 'team', 'training', 'documents', 'closeout'] as const)"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="tab === t"
                :data-test="`tab-${t}`"
                class="-mb-px border-b-2 px-4 py-2 capitalize"
                :class="tab === t ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-surface-500 hover:text-surface-800'"
                @click="tab = t"
            >
                {{ t }}
            </button>
        </nav>

        <section v-if="tab === 'milestones'">
            <div class="mb-3 flex justify-end">
                <Button
                    v-if="can.edit"
                    data-test="add-milestone"
                    @click="openMilestone(null)"
                >
                    Add milestone
                </Button>
            </div>
            <p
                v-if="!milestones.length"
                class="text-sm text-surface-500"
            >
                No milestones yet.
            </p>
            <Card
                v-for="m in milestones"
                :key="m.id"
                class="mb-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">
                            {{ m.title }} <StatusChip
                                :status="m.status === 'done' ? 'complete' : m.status === 'blocked' ? 'rejected' : 'pending'"
                                :label="m.status.replace('_', ' ')"
                            />
                            <StatusChip
                                v-if="m.overdue"
                                status="rejected"
                                label="overdue"
                            />
                        </p>
                        <p class="text-xs text-surface-500">
                            Due {{ m.due_on ?? '—' }}<span v-if="m.billable_amount"> · billable <Amount :value="m.billable_amount" /></span>
                        </p>
                        <p
                            v-if="m.deliverable"
                            class="mt-1 text-sm"
                        >
                            {{ m.deliverable }}
                        </p>
                        <p
                            v-if="m.status === 'blocked' && m.blocker"
                            class="mt-1 rounded bg-red-50 px-2 py-1 text-xs text-red-800"
                        >
                            Blocker: {{ m.blocker }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Link
                            v-if="can.invoice && m.billable_amount"
                            :href="route('commercial.invoices.create', { milestone_id: m.id })"
                            class="text-sm text-brand-700 hover:underline"
                        >
                            Invoice milestone
                        </Link>
                        <Button
                            v-if="can.edit"
                            variant="secondary"
                            @click="openMilestone(m)"
                        >
                            Edit
                        </Button>
                    </div>
                </div>
                <ul class="mt-3 divide-y divide-surface-100 text-sm">
                    <li
                        v-for="t in m.tasks"
                        :key="t.id"
                        class="flex items-center gap-3 py-1.5"
                    >
                        <select
                            v-if="can.edit"
                            :value="t.status"
                            :aria-label="`Status of ${t.title}`"
                            class="rounded border border-surface-200 px-1 py-0.5 text-xs"
                            @change="taskStatus(t, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="todo">
                                to do
                            </option><option value="doing">
                                doing
                            </option><option value="done">
                                done
                            </option>
                        </select>
                        <span :class="{ 'line-through text-surface-400': t.status === 'done' }">{{ t.title }}</span>
                        <span class="ml-auto text-xs text-surface-500">{{ t.assignee ?? '' }} {{ t.due_on ?? '' }}</span>
                    </li>
                </ul>
                <form
                    v-if="can.edit"
                    class="mt-2 flex flex-wrap gap-2 text-sm"
                    @submit.prevent="addTask(m)"
                >
                    <input
                        v-model="task.title"
                        placeholder="New task"
                        aria-label="New task"
                        class="flex-1 rounded-md border border-surface-300 px-2 py-1"
                    >
                    <select
                        v-model="task.assignee_staff_id"
                        aria-label="Assignee"
                        class="rounded-md border border-surface-300 px-2 py-1"
                    >
                        <option value="">
                            Unassigned
                        </option>
                        <option
                            v-for="s in staffOptions"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                    <input
                        v-model="task.due_on"
                        type="date"
                        aria-label="Due"
                        class="rounded-md border border-surface-300 px-2 py-1"
                    >
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Add task
                    </Button>
                </form>
            </Card>
        </section>

        <section v-if="tab === 'team'">
            <Card title="Team">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-surface-500">
                        <tr>
                            <th class="py-2">
                                Member
                            </th><th>Role</th><th>From</th><th>To</th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in members"
                            :key="m.id"
                            class="border-t border-surface-100"
                        >
                            <td class="py-2">
                                {{ m.name }}
                            </td><td>{{ m.role }}</td><td>{{ m.from_date }}</td><td>{{ m.to_date ?? '—' }}</td>
                            <td class="text-right">
                                <button
                                    v-if="can.edit && !m.to_date"
                                    type="button"
                                    class="text-xs text-surface-500 hover:underline"
                                    @click="endMember(m)"
                                >
                                    End assignment
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!members.length">
                            <td
                                colspan="5"
                                class="py-3 text-surface-500"
                            >
                                No team members. Only assigned staff can book time to this project (UAT-07).
                            </td>
                        </tr>
                    </tbody>
                </table>
                <form
                    v-if="can.edit"
                    class="mt-3 flex flex-wrap items-end gap-2 text-sm"
                    @submit.prevent="addMember"
                >
                    <FormField
                        id="m-staff"
                        label="Staff"
                        :error="member.errors.staff_profile_id"
                    >
                        <select
                            id="m-staff"
                            v-model="member.staff_profile_id"
                            required
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                            <option
                                v-for="s in staffOptions"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="m-role"
                        label="Role"
                    >
                        <select
                            id="m-role"
                            v-model="member.role"
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                            <option value="pm">
                                PM
                            </option><option value="lead">
                                Lead
                            </option><option value="member">
                                Member
                            </option><option value="trainer">
                                Trainer
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="m-from"
                        label="From"
                    >
                        <input
                            id="m-from"
                            v-model="member.from_date"
                            type="date"
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                    </FormField>
                    <FormField
                        id="m-to"
                        label="To (optional)"
                    >
                        <input
                            id="m-to"
                            v-model="member.to_date"
                            type="date"
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                    </FormField>
                    <Button
                        type="submit"
                        data-test="add-member"
                    >
                        Assign
                    </Button>
                </form>
            </Card>
        </section>

        <section v-if="tab === 'training'">
            <Card title="Training sessions">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="s in sessions"
                        :key="s.id"
                        class="flex flex-wrap items-center gap-3 py-2"
                    >
                        <Link
                            :href="route('projects.sessions.show', s.id)"
                            class="font-medium text-brand-700 hover:underline"
                        >
                            {{ s.course_title }}
                        </Link>
                        <span class="text-surface-500">{{ s.session_date }} · {{ s.venue ?? '' }} · {{ s.trainer ?? 'no trainer' }}</span>
                        <StatusChip
                            :status="s.status === 'completed' ? 'complete' : s.status === 'cancelled' ? 'cancelled' : 'pending'"
                            :label="s.status"
                        />
                        <span class="ml-auto text-xs text-surface-500">{{ s.participants }} participants<span v-if="s.feedback_score"> · feedback {{ s.feedback_score }}/5</span></span>
                    </li>
                    <li
                        v-if="!sessions.length"
                        class="py-2 text-surface-500"
                    >
                        No sessions yet.
                    </li>
                </ul>
                <form
                    v-if="can.edit"
                    class="mt-3 flex flex-wrap items-end gap-2 text-sm"
                    @submit.prevent="addSession"
                >
                    <FormField
                        id="s-title"
                        label="Course"
                        :error="session.errors.course_title"
                    >
                        <input
                            id="s-title"
                            v-model="session.course_title"
                            class="w-64 rounded-md border border-surface-300 px-2 py-2"
                        >
                    </FormField>
                    <FormField
                        id="s-date"
                        label="Date"
                        :error="session.errors.session_date"
                    >
                        <input
                            id="s-date"
                            v-model="session.session_date"
                            type="date"
                            required
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                    </FormField>
                    <FormField
                        id="s-venue"
                        label="Venue"
                    >
                        <input
                            id="s-venue"
                            v-model="session.venue"
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                    </FormField>
                    <FormField
                        id="s-trainer"
                        label="Trainer"
                    >
                        <select
                            id="s-trainer"
                            v-model="session.trainer_staff_id"
                            class="rounded-md border border-surface-300 px-2 py-2"
                        >
                            <option value="">
                                —
                            </option>
                            <option
                                v-for="s in staffOptions"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                    </FormField>
                    <Button type="submit">
                        Add session
                    </Button>
                </form>
            </Card>
        </section>

        <section v-if="tab === 'documents'">
            <Card
                title="Project documents"
                description="Contracts, LOAs, reports and evidence. Visible to people who can see this project, plus anyone a document is explicitly shared with (M02-07)."
            >
                <EvidencePanel
                    entity-type="project"
                    :entity-id="project.id"
                    link-type="contract"
                    :documents="documents"
                    :can-attach="can.edit"
                />
            </Card>
        </section>

        <section v-if="tab === 'closeout'">
            <Card
                title="Closeout checklist"
                description="All items must be ticked before the project can be closed. An invoice may still be unpaid after delivery is complete (WF-04)."
            >
                <form
                    class="space-y-2 text-sm"
                    @submit.prevent="closeout.post(route('projects.projects.closeout', project.id), { preserveScroll: true })"
                >
                    <label
                        v-for="(label, key) in closeoutItems"
                        :key="key"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="closeout.items[key]"
                            type="checkbox"
                            :disabled="!can.edit"
                        > {{ label }}
                    </label>
                    <Button
                        v-if="can.edit"
                        type="submit"
                        variant="secondary"
                    >
                        Save checklist
                    </Button>
                </form>
            </Card>
        </section>

        <Modal
            :open="msOpen"
            :title="msEditing ? 'Edit milestone' : 'Add milestone'"
            size="md"
            @close="msOpen = false"
        >
            <form
                id="ms-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="saveMilestone"
            >
                <FormField
                    id="ms-title"
                    label="Title"
                    :error="ms.errors.title"
                    class="col-span-2"
                >
                    <input
                        id="ms-title"
                        v-model="ms.title"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="ms-deliverable"
                    label="Deliverable"
                    class="col-span-2"
                >
                    <textarea
                        id="ms-deliverable"
                        v-model="ms.deliverable"
                        rows="2"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    />
                </FormField>
                <FormField
                    id="ms-due"
                    label="Due"
                >
                    <input
                        id="ms-due"
                        v-model="ms.due_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="ms-status"
                    label="Status"
                >
                    <select
                        id="ms-status"
                        v-model="ms.status"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="pending">
                            Pending
                        </option><option value="in_progress">
                            In progress
                        </option><option value="blocked">
                            Blocked
                        </option><option value="done">
                            Done
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="ms.status === 'blocked'"
                    id="ms-blocker"
                    label="Blocker"
                    :error="ms.errors.blocker || fieldError(ms.errors, 'general')"
                    class="col-span-2"
                >
                    <input
                        id="ms-blocker"
                        v-model="ms.blocker"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="ms-bill"
                    label="Billable amount (for milestone invoicing)"
                    class="col-span-2"
                >
                    <MoneyInput
                        id="ms-bill"
                        v-model="ms.billable_amount"
                    />
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="msOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="ms-form"
                    :disabled="ms.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>

        <Modal
            :open="editOpen"
            title="Edit project"
            size="lg"
            @close="editOpen = false"
        >
            <form
                id="edit-project"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="saveProject"
            >
                <FormField
                    id="e-name"
                    label="Name"
                    :error="edit.errors.name"
                    class="col-span-2"
                >
                    <input
                        id="e-name"
                        v-model="edit.name"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-pm"
                    label="Project manager"
                >
                    <select
                        id="e-pm"
                        v-model="edit.pm_user_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            —
                        </option>
                        <option
                            v-for="u in pmOptions"
                            :key="u.id"
                            :value="u.id"
                        >
                            {{ u.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="e-value"
                    label="Contract value"
                    :error="edit.errors.contract_value"
                >
                    <MoneyInput
                        id="e-value"
                        v-model="edit.contract_value"
                    />
                </FormField>
                <FormField
                    id="e-budget"
                    label="Cost budget"
                >
                    <MoneyInput
                        id="e-budget"
                        v-model="edit.cost_budget"
                    />
                </FormField>
                <FormField
                    id="e-start"
                    label="Start"
                >
                    <input
                        id="e-start"
                        v-model="edit.starts_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-end"
                    label="End"
                    :error="edit.errors.ends_on"
                >
                    <input
                        id="e-end"
                        v-model="edit.ends_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-scope"
                    label="Scope"
                    class="col-span-2"
                >
                    <textarea
                        id="e-scope"
                        v-model="edit.scope"
                        rows="4"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    />
                </FormField>
                <FormField
                    id="e-billing"
                    label="Billing terms"
                    class="col-span-2"
                >
                    <input
                        id="e-billing"
                        v-model="edit.billing_terms"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="editOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="edit-project"
                    :disabled="edit.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
