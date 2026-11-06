<script setup lang="ts">
/** Staff profile (M04-01): masked identifiers with audited reveal (M04-06), restricted bank record, versioned rates (WF-05). */
import { Link, useForm } from '@inertiajs/vue3';
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
import { revealValue } from '@/lib/reveal';
import type { LinkedDoc } from '@/types/finance';

interface Staff { id: number; staff_no: string; name: string; legal_name: string; id_masked: string | null; id_type: string; engagement_type: string; classification: string; position: string | null; joined_on: string | null; ended_on: string | null; status: string; email: string | null; phone: string | null; notes: string | null; contractor_invoice_required: boolean; classification_reviewed_at: string | null; user_id: number | null }
interface Rate { id: number; version: number; rate_type: string; rate_amount: string; effective_from: string; effective_to: string | null; title: string | null; locked: boolean }
const props = defineProps<{
    staff: Staff; bank: { bank_name: string; masked: string; updated_at: string | null } | null; engagements: Rate[];
    assignments: { id: number; code: string; name: string; role: string; from_date: string; to_date: string | null }[];
    memos: { id: number; subject: string; delivered_at: string; read_at: string | null }[]; documents: LinkedDoc[];
    can: { edit: boolean; revealId: boolean; revealBank: boolean; editBank: boolean; addRate: boolean; attach: boolean };
}>();
const revealedId = ref<string | null>(null);
const revealedBank = ref<{ bank_name: string; account_no: string; account_name: string } | null>(null);
const error = ref<string | null>(null);
async function reveal(kind: 'id' | 'bank') {
    const reason = window.prompt(kind === 'id' ? 'Why do you need the full identification number? (audited)' : 'Why do you need the full bank account? (audited)');
    if (!reason) return;
    error.value = null;
    try {
        if (kind === 'id') revealedId.value = await revealValue<string>(route('hr.staff.reveal-id', props.staff.id), reason);
        else revealedBank.value = await revealValue<{ bank_name: string; account_no: string; account_name: string }>(route('hr.staff.reveal-bank', props.staff.id), reason);
    } catch (e) { error.value = (e as Error).message; }
}
const s = props.staff;
const editOpen = ref(false);
const edit = useForm({ legal_name: s.legal_name, preferred_name: '', engagement_type: s.engagement_type, classification: s.classification, id_type: s.id_type, id_number: '', email: s.email ?? '', phone: s.phone ?? '', position: s.position ?? '', joined_on: s.joined_on ?? '', ended_on: s.ended_on ?? '', status: s.status, notes: s.notes ?? '', user_id: s.user_id });
const saveEdit = () => edit.transform((d) => ({ ...d, id_number: d.id_number || null, joined_on: d.joined_on || null, ended_on: d.ended_on || null, preferred_name: d.preferred_name || null })).put(route('hr.staff.update', s.id), { preserveScroll: true, onSuccess: () => { editOpen.value = false; } });
const bankOpen = ref(false);
const bankForm = useForm({ bank_name: '', account_no: '', account_name: s.legal_name });
const rate = useForm({ rate_type: props.engagements[0]?.rate_type ?? 'hourly', rate_amount: '' as string | number | null, effective_from: new Date().toISOString().slice(0, 10), title: '' });
const addRate = () => rate.transform((d) => ({ ...d, rate_amount: String(d.rate_amount ?? '') })).post(route('hr.staff.engagements.store', s.id), { preserveScroll: true, onSuccess: () => rate.reset('rate_amount', 'title') });
const unit: Record<string, string> = { hourly: 'hour', daily: 'day', per_session: 'session', fixed: 'engagement' };
</script>

<template>
    <AppLayout
        :title="staff.name"
        :status="staff.status"
        :breadcrumbs="[{ label: 'People' }, { label: 'Staff directory', href: route('hr.staff.index') }, { label: staff.staff_no }]"
    >
        <template #actions>
            <Button
                v-if="can.edit"
                variant="secondary"
                @click="editOpen = true"
            >
                Edit profile
            </Button>
        </template>
        <p
            v-if="error"
            class="rounded-md bg-red-50 p-3 text-sm text-red-800"
        >
            {{ error }}
        </p>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Profile"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Legal name
                        </dt><dd class="font-medium">
                            {{ staff.legal_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            {{ staff.id_type === 'nric' ? 'NRIC' : 'Passport' }}
                        </dt>
                        <dd class="flex items-center gap-2">
                            <span
                                class="font-mono"
                                data-test="id-value"
                            >{{ revealedId ?? staff.id_masked ?? '—' }}</span>
                            <button
                                v-if="can.revealId && staff.id_masked && !revealedId"
                                type="button"
                                class="text-xs text-brand-700 hover:underline"
                                data-test="reveal-id"
                                @click="reveal('id')"
                            >
                                Reveal
                            </button>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Engagement · classification
                        </dt>
                        <dd>
                            {{ staff.engagement_type.replace('_', ' ') }} · {{ staff.classification }}
                            <span
                                v-if="staff.contractor_invoice_required"
                                class="block text-xs text-amber-700"
                            >Contractor invoice required before payment (M04-05)</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Position
                        </dt><dd>{{ staff.position ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Contact
                        </dt><dd>{{ staff.email ?? '' }} {{ staff.phone ?? '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Joined / ended
                        </dt><dd>{{ staff.joined_on ?? '—' }} / {{ staff.ended_on ?? '—' }}</dd>
                    </div>
                </dl>
                <p
                    v-if="staff.notes"
                    class="mt-3 whitespace-pre-line rounded bg-surface-50 p-2 text-sm"
                >
                    {{ staff.notes }}
                </p>
            </Card>
            <Card
                title="Bank details"
                description="Restricted record, stored encrypted (M04-06)."
            >
                <template v-if="bank">
                    <p class="text-sm">
                        {{ revealedBank?.bank_name ?? bank.bank_name }}
                    </p>
                    <p
                        class="font-mono text-lg"
                        data-test="bank-value"
                    >
                        {{ revealedBank?.account_no ?? bank.masked }}
                    </p>
                    <p
                        v-if="revealedBank"
                        class="text-sm"
                    >
                        {{ revealedBank.account_name }}
                    </p>
                    <button
                        v-if="can.revealBank && !revealedBank"
                        type="button"
                        class="mt-1 text-xs text-brand-700 hover:underline"
                        @click="reveal('bank')"
                    >
                        Reveal account
                    </button>
                </template>
                <p
                    v-else
                    class="text-sm text-surface-500"
                >
                    Not on file.
                </p>
                <Button
                    v-if="can.editBank"
                    class="mt-3"
                    variant="secondary"
                    @click="bankOpen = true"
                >
                    {{ bank ? 'Replace' : 'Add' }} bank details
                </Button>
            </Card>
        </div>

        <Card
            title="Rate agreements"
            description="A rate version is locked once it is used for a payment; a new rate is always a new version (WF-05)."
        >
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-surface-500">
                    <tr>
                        <th class="py-2">
                            Version
                        </th><th>Rate</th><th>From</th><th>To</th><th>Agreement</th><th />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in engagements"
                        :key="e.id"
                        class="border-t border-surface-100"
                    >
                        <td class="py-2">
                            v{{ e.version }}
                        </td><td><Amount :value="e.rate_amount" /> / {{ unit[e.rate_type] }}</td><td>{{ e.effective_from }}</td><td>{{ e.effective_to ?? 'open' }}</td><td>{{ e.title ?? '—' }}</td>
                        <td>
                            <StatusChip
                                v-if="e.locked"
                                status="locked"
                                label="locked"
                            />
                        </td>
                    </tr>
                    <tr v-if="!engagements.length">
                        <td
                            colspan="6"
                            class="py-3 text-surface-500"
                        >
                            No rate agreement yet.
                        </td>
                    </tr>
                </tbody>
            </table>
            <form
                v-if="can.addRate"
                class="mt-3 flex flex-wrap items-end gap-2 text-sm"
                @submit.prevent="addRate"
            >
                <FormField
                    id="r-type"
                    label="Rate type"
                >
                    <select
                        id="r-type"
                        v-model="rate.rate_type"
                        class="rounded-md border border-surface-300 px-2 py-2"
                    >
                        <option value="hourly">
                            Hourly
                        </option><option value="daily">
                            Daily
                        </option><option value="per_session">
                            Per session
                        </option><option value="fixed">
                            Fixed
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="r-amount"
                    label="Rate (MYR)"
                    :error="rate.errors.rate_amount"
                >
                    <MoneyInput
                        id="r-amount"
                        v-model="rate.rate_amount"
                    />
                </FormField>
                <FormField
                    id="r-from"
                    label="Effective from"
                    :error="rate.errors.effective_from"
                >
                    <input
                        id="r-from"
                        v-model="rate.effective_from"
                        type="date"
                        class="rounded-md border border-surface-300 px-2 py-2"
                    >
                </FormField>
                <FormField
                    id="r-title"
                    label="Agreement ref."
                >
                    <input
                        id="r-title"
                        v-model="rate.title"
                        class="rounded-md border border-surface-300 px-2 py-2"
                    >
                </FormField>
                <Button
                    type="submit"
                    data-test="add-rate"
                >
                    Add version
                </Button>
            </form>
        </Card>

        <div class="grid gap-5 xl:grid-cols-2">
            <Card title="Project assignments">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="a in assignments"
                        :key="`${a.id}-${a.from_date}`"
                        class="flex justify-between py-1.5"
                    >
                        <Link
                            :href="route('projects.projects.show', a.id)"
                            class="text-brand-700 hover:underline"
                        >
                            {{ a.code }} {{ a.name }}
                        </Link>
                        <span class="text-surface-500">{{ a.role }} · {{ a.from_date }} → {{ a.to_date ?? 'open' }}</span>
                    </li>
                    <li
                        v-if="!assignments.length"
                        class="py-2 text-surface-500"
                    >
                        Not assigned to any project.
                    </li>
                </ul>
            </Card>
            <Card title="Memos & letters">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="m in memos"
                        :key="m.id"
                        class="flex justify-between py-1.5"
                    >
                        <span>{{ m.subject }}</span><span class="text-surface-500">{{ m.delivered_at.slice(0, 10) }}</span>
                    </li>
                    <li
                        v-if="!memos.length"
                        class="py-2 text-surface-500"
                    >
                        None.
                    </li>
                </ul>
            </Card>
        </div>
        <Card
            title="Staff documents"
            description="Contracts, NDAs and certificates (staff document folder, M04-01)."
        >
            <EvidencePanel
                entity-type="staff_profile"
                :entity-id="staff.id"
                link-type="contract"
                classification="restricted"
                :documents="documents"
                :can-attach="can.attach"
            />
        </Card>

        <Modal
            :open="bankOpen"
            title="Bank details"
            size="md"
            @close="bankOpen = false"
        >
            <form
                id="bank-form"
                class="grid gap-3"
                @submit.prevent="bankForm.put(route('hr.staff.bank', staff.id), { preserveScroll: true, onSuccess: () => { bankOpen = false; bankForm.reset(); } })"
            >
                <FormField
                    id="b-bank"
                    label="Bank"
                    :error="bankForm.errors.bank_name"
                >
                    <input
                        id="b-bank"
                        v-model="bankForm.bank_name"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="b-no"
                    label="Account number"
                    :error="bankForm.errors.account_no"
                >
                    <input
                        id="b-no"
                        v-model="bankForm.account_no"
                        required
                        autocomplete="off"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="b-name"
                    label="Account name"
                >
                    <input
                        id="b-name"
                        v-model="bankForm.account_name"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="bankOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="bank-form"
                    :disabled="bankForm.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>

        <Modal
            :open="editOpen"
            title="Edit profile"
            size="lg"
            @close="editOpen = false"
        >
            <form
                id="edit-staff"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="saveEdit"
            >
                <FormField
                    id="e-legal"
                    label="Legal name"
                    :error="edit.errors.legal_name"
                    class="col-span-2"
                >
                    <input
                        id="e-legal"
                        v-model="edit.legal_name"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-pos"
                    label="Position"
                >
                    <input
                        id="e-pos"
                        v-model="edit.position"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-class"
                    label="Classification"
                    :error="edit.errors.classification"
                >
                    <select
                        id="e-class"
                        v-model="edit.classification"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="employee">
                            Employee
                        </option><option value="contractor">
                            Contractor
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="e-id"
                    label="New ID number (leave blank to keep)"
                    :error="edit.errors.id_number"
                >
                    <input
                        id="e-id"
                        v-model="edit.id_number"
                        autocomplete="off"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-status"
                    label="Status"
                >
                    <select
                        id="e-status"
                        v-model="edit.status"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="active">
                            Active
                        </option><option value="inactive">
                            Inactive
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="e-email"
                    label="Email"
                >
                    <input
                        id="e-email"
                        v-model="edit.email"
                        type="email"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-phone"
                    label="Phone"
                >
                    <input
                        id="e-phone"
                        v-model="edit.phone"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-ended"
                    label="End date"
                >
                    <input
                        id="e-ended"
                        v-model="edit.ended_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="e-notes"
                    label="HR notes"
                    class="col-span-2"
                >
                    <textarea
                        id="e-notes"
                        v-model="edit.notes"
                        rows="3"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    />
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
                    form="edit-staff"
                    :disabled="edit.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
