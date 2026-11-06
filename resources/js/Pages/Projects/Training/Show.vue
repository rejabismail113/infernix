<script setup lang="ts">
/** Training session (M02-06): participants with consent, attendance, feedback; certificates via M03 (M03-06). */
import { Link, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Participant { id: number | null; name: string; organisation: string | null; email: string | null; consent_given: boolean; attended: boolean; feedback_score: string | null; certificate?: { id: number; number: string; status: string; pdf_document_id: number | null; verify_url: string | null } | null }
const props = defineProps<{
    session: { id: number; course_title: string; session_date: string; venue: string | null; status: string; trainer_staff_id: number | null; trainer: string | null; feedback_score: string | null; feedback_comments: string | null };
    project: { id: number; code: string; name: string }; participants: Participant[]; trainers: { id: number; label: string }[]; can: { edit: boolean };
}>();
const s = useForm({ course_title: props.session.course_title, session_date: props.session.session_date, venue: props.session.venue ?? '', trainer_staff_id: (props.session.trainer_staff_id ?? '') as number | '', status: props.session.status, feedback_comments: props.session.feedback_comments ?? '' });
const saveSession = () => s.transform((d) => ({ ...d, trainer_staff_id: d.trainer_staff_id || null })).put(route('projects.sessions.update', props.session.id), { preserveScroll: true });
const rows = () => props.participants.map((x) => ({ id: x.id, name: x.name, organisation: x.organisation ?? '', email: x.email ?? '', consent_given: x.consent_given, attended: x.attended, feedback_score: x.feedback_score ?? '' }));
const p = useForm({ participants: rows() });
// After a save the server returns ids / certificates: re-sync the editable rows.
watch(() => props.participants, () => { p.defaults({ participants: rows() }); p.reset(); });
const add = () => p.participants.push({ id: null, name: '', organisation: '', email: '', consent_given: false, attended: false, feedback_score: '' });
const saveParticipants = () => p.transform((d) => ({ participants: d.participants.filter((x) => x.name.trim() !== '').map((x) => ({ ...x, feedback_score: x.feedback_score === '' ? null : x.feedback_score, email: x.email || null })) }))
    .post(route('projects.sessions.participants', props.session.id), { preserveScroll: true });
const cert = (id: number | null) => props.participants.find((x) => x.id === id)?.certificate ?? null;
</script>

<template>
    <AppLayout
        :title="session.course_title"
        :status="session.status"
        :breadcrumbs="[{ label: 'Projects', href: route('projects.projects.index') }, { label: project.code, href: route('projects.projects.show', project.id) }, { label: 'Training session' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'projects.training-register')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Training register
            </Link>
            <Button
                v-if="can.edit && session.status === 'completed'"
                data-test="issue-certificates"
                @click="router.post(route('projects.sessions.certificates', session.id), {}, { preserveScroll: true })"
            >
                Issue certificates
            </Button>
        </template>
        <Card title="Session">
            <form
                class="grid gap-3 md:grid-cols-3"
                @submit.prevent="saveSession"
            >
                <FormField
                    id="s-course"
                    label="Course"
                    :error="s.errors.course_title"
                >
                    <input
                        id="s-course"
                        v-model="s.course_title"
                        :disabled="!can.edit"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    id="s-date"
                    label="Date"
                >
                    <input
                        id="s-date"
                        v-model="s.session_date"
                        type="date"
                        :disabled="!can.edit"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    id="s-venue"
                    label="Venue"
                >
                    <input
                        id="s-venue"
                        v-model="s.venue"
                        :disabled="!can.edit"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    id="s-trainer"
                    label="Trainer"
                >
                    <select
                        v-if="can.edit"
                        id="s-trainer"
                        v-model="s.trainer_staff_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <option value="">
                            —
                        </option>
                        <option
                            v-for="t in trainers"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                    <p
                        v-else
                        class="py-2 text-sm"
                    >
                        {{ session.trainer ?? '—' }}
                    </p>
                </FormField>
                <FormField
                    id="s-status"
                    label="Status"
                >
                    <select
                        id="s-status"
                        v-model="s.status"
                        :disabled="!can.edit"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <option value="planned">
                            Planned
                        </option><option value="completed">
                            Completed
                        </option><option value="cancelled">
                            Cancelled
                        </option>
                    </select>
                </FormField>
                <div class="flex items-end">
                    <p class="text-sm">
                        Average feedback: <strong>{{ session.feedback_score ?? '—' }}</strong> / 5
                    </p>
                </div>
                <FormField
                    id="s-comments"
                    label="Feedback comments"
                    class="md:col-span-3"
                >
                    <textarea
                        id="s-comments"
                        v-model="s.feedback_comments"
                        :disabled="!can.edit"
                        rows="2"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    />
                </FormField>
                <div v-if="can.edit">
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Save session
                    </Button>
                </div>
            </form>
        </Card>
        <Card
            title="Participants"
            description="Personal data: record consent before issuing certificates or follow-ups (PDPA). Certificates go only to attendees who consented."
        >
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead class="text-left text-xs uppercase text-surface-500">
                        <tr>
                            <th class="py-2">
                                Name
                            </th><th>Organisation</th><th>Email</th><th class="text-center">
                                Consent
                            </th><th class="text-center">
                                Attended
                            </th><th class="w-20">
                                Score
                            </th><th>Certificate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(x, i) in p.participants"
                            :key="i"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1 pr-2">
                                <input
                                    v-model="x.name"
                                    :disabled="!can.edit || !!cert(x.id)"
                                    :aria-label="`Participant ${i + 1} name`"
                                    class="w-full rounded border border-surface-200 px-2 py-1"
                                >
                            </td>
                            <td class="pr-2">
                                <input
                                    v-model="x.organisation"
                                    :disabled="!can.edit"
                                    :aria-label="`Participant ${i + 1} organisation`"
                                    class="w-full rounded border border-surface-200 px-2 py-1"
                                >
                            </td>
                            <td class="pr-2">
                                <input
                                    v-model="x.email"
                                    type="email"
                                    :disabled="!can.edit"
                                    :aria-label="`Participant ${i + 1} email`"
                                    class="w-full rounded border border-surface-200 px-2 py-1"
                                >
                            </td>
                            <td class="text-center">
                                <input
                                    v-model="x.consent_given"
                                    type="checkbox"
                                    :disabled="!can.edit"
                                    :aria-label="`Participant ${i + 1} consent`"
                                >
                            </td>
                            <td class="text-center">
                                <input
                                    v-model="x.attended"
                                    type="checkbox"
                                    :disabled="!can.edit"
                                    :aria-label="`Participant ${i + 1} attended`"
                                >
                            </td>
                            <td class="pr-2">
                                <input
                                    v-model="x.feedback_score"
                                    inputmode="decimal"
                                    :disabled="!can.edit"
                                    :aria-label="`Participant ${i + 1} score`"
                                    class="w-full rounded border border-surface-200 px-2 py-1"
                                >
                            </td>
                            <td>
                                <template v-if="cert(x.id)">
                                    <a
                                        v-if="cert(x.id)?.pdf_document_id"
                                        :href="route('documents.download', { document: cert(x.id)?.pdf_document_id })"
                                        class="inline-flex items-center gap-1 font-mono text-brand-700 hover:underline"
                                    ><Icon
                                        name="download"
                                        :size="14"
                                    /> {{ cert(x.id)?.number }}</a>
                                    <StatusChip
                                        v-if="cert(x.id)?.status === 'void'"
                                        status="void"
                                    />
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="can.edit"
                class="mt-3 flex gap-2"
            >
                <Button
                    type="button"
                    variant="secondary"
                    data-test="add-participant"
                    @click="add"
                >
                    Add participant
                </Button>
                <Button
                    :disabled="p.processing"
                    data-test="save-participants"
                    @click="saveParticipants"
                >
                    Save participants
                </Button>
            </div>
        </Card>
    </AppLayout>
</template>
