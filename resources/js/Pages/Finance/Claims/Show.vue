<script setup lang="ts">
import { fieldError } from '@/lib/errors';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import Stepper from '@/Components/Stepper.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ClaimRow } from '@/types/finance';

const props = defineProps<{
    claim: ClaimRow;
    approvals: { level: number; approver: string; decision: string; owner_override: boolean; delegated: boolean; reason: string | null; decided_at: string }[];
    can: { edit: boolean; submit: boolean; decide: boolean; needsOverride: boolean; close: boolean; cancel: boolean; pay: boolean };
}>();
const steps = [{ key: 'draft', label: 'Draft' }, { key: 'pending_approval', label: 'Approval' }, { key: 'finance_payable', label: 'Payable' }, { key: 'paid', label: 'Paid' }, { key: 'closed', label: 'Closed' }];
const current = computed(() => (props.claim.status === 'rejected' ? 'draft' : steps.some((s) => s.key === props.claim.status) ? props.claim.status : 'draft'));
const approve = useForm({ override_reason: '' });
const rejectOpen = ref(false);
const reject = useForm<{ reason: string; lines: Record<number, string> }>({ reason: '', lines: {} });
const editable = computed(() => props.can.edit);
</script>

<template>
    <AppLayout
        :title="claim.claim_no"
        :status="claim.status"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Claims', href: route('finance.claims.index') }, { label: claim.claim_no }]"
    >
        <template #actions>
            <Link
                v-if="can.edit"
                :href="route('finance.claims.edit', claim.id)"
                class="rounded-md border border-surface-300 bg-white px-4 py-2 text-sm font-medium hover:bg-surface-50"
            >
                Edit
            </Link>
            <Button
                v-if="can.submit"
                @click="router.post(route('finance.claims.submit', claim.id), {}, { preserveScroll: true })"
            >
                Submit for approval
            </Button>
            <Link
                v-if="can.pay"
                :href="route('finance.payments.create', { target_type: 'claim', target_id: claim.id })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Pay claim
            </Link>
            <Button
                v-if="can.close"
                variant="secondary"
                @click="router.post(route('finance.claims.close', claim.id))"
            >
                Close
            </Button>
            <Button
                v-if="can.cancel"
                variant="secondary"
                @click="router.post(route('finance.claims.cancel', claim.id))"
            >
                Cancel claim
            </Button>
        </template>
        <Stepper
            :steps="steps"
            :current="current"
        />
        <div
            v-if="claim.status === 'rejected'"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
            role="alert"
        >
            Returned for correction: {{ claim.rejection_reason }}
        </div>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Summary"
                class="xl:col-span-1"
            >
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-surface-500">
                        Staff
                    </dt><dd>{{ claim.staff }}</dd>
                    <dt class="text-surface-500">
                        Purpose
                    </dt><dd>{{ claim.purpose }}</dd>
                    <dt class="text-surface-500">
                        Total
                    </dt><dd><Amount :value="claim.total_amount" /></dd>
                    <dt class="text-surface-500">
                        Approved
                    </dt><dd><Amount :value="claim.approved_amount" /></dd>
                    <dt class="text-surface-500">
                        Paid
                    </dt><dd><Amount :value="claim.paid_amount" /></dd>
                    <dt class="text-surface-500">
                        Ledger
                    </dt><dd>
                        <Link
                            v-if="claim.journal_id"
                            :href="route('finance.journals.show', claim.journal_id)"
                            class="text-brand-700 hover:underline"
                        >
                            Journal #{{ claim.journal_id }}
                        </Link><span v-else>—</span>
                    </dd>
                </dl>
            </Card>
            <Card
                v-if="can.decide"
                :title="`Approval · level ${claim.current_level}`"
                class="xl:col-span-2"
            >
                <form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="approve.post(route('finance.claims.approve', claim.id), { preserveScroll: true })"
                >
                    <input
                        v-if="can.needsOverride"
                        v-model="approve.override_reason"
                        required
                        placeholder="Owner override reason (this is your own claim — audited)"
                        aria-label="Override reason"
                        class="min-w-80 flex-1 rounded-md border border-amber-300 px-3 py-2 text-sm"
                    >
                    <Button
                        type="submit"
                        :disabled="approve.processing"
                    >
                        Approve
                    </Button>
                    <Button
                        variant="danger"
                        @click="rejectOpen = true"
                    >
                        Return for correction
                    </Button>
                </form>
                <p
                    v-if="fieldError(approve.errors, 'general')"
                    class="mt-2 text-sm text-red-600"
                    role="alert"
                >
                    {{ fieldError(approve.errors, 'general') }}
                </p>
            </Card>
        </div>

        <Card title="Lines">
            <div class="divide-y divide-surface-100">
                <div
                    v-for="l in claim.lines ?? []"
                    :key="l.id"
                    class="grid gap-3 py-3 md:grid-cols-[1fr_auto]"
                >
                    <div>
                        <p class="font-medium">
                            {{ l.line_no }}. {{ l.claim_type }} — {{ l.description }}
                            <StatusChip
                                :status="l.status"
                                class="ml-1"
                            />
                        </p>
                        <p class="text-sm text-surface-500">
                            {{ l.incurred_on }}
                            <template v-if="l.claim_type_code === 'mileage'">
                                · {{ l.origin }} → {{ l.destination }} · {{ l.distance_km }} km × RM {{ l.rate_snapshot ?? '—' }}
                            </template>
                        </p>
                        <p
                            v-if="l.is_medical"
                            class="text-sm"
                        >
                            <span
                                v-if="l.clinical_note_masked"
                                class="italic text-surface-500"
                            >Clinical detail restricted (HR/Owner only)</span>
                            <span v-else-if="l.clinical_note">Clinical note: {{ l.clinical_note }}</span>
                        </p>
                        <p
                            v-if="l.rejection_reason"
                            class="text-sm text-red-700"
                        >
                            {{ l.rejection_reason }}
                        </p>
                        <div class="mt-2">
                            <EvidencePanel
                                entity-type="claim_line"
                                :entity-id="l.id"
                                :documents="l.documents"
                                :can-attach="editable"
                                :classification="l.is_medical ? 'medical' : 'confidential'"
                                compact
                            />
                            <p
                                v-if="l.receipt_required && l.documents.length === 0"
                                class="text-xs text-amber-700"
                            >
                                Receipt required before submission.
                            </p>
                        </div>
                    </div>
                    <div class="text-right text-lg font-semibold">
                        <Amount :value="l.amount" />
                    </div>
                </div>
            </div>
        </Card>

        <Card
            v-if="approvals.length"
            title="Approval history"
        >
            <ul class="space-y-1 text-sm">
                <li
                    v-for="(a, i) in approvals"
                    :key="i"
                >
                    Level {{ a.level }} · <strong>{{ a.decision }}</strong> by {{ a.approver }} · {{ a.decided_at.slice(0, 16).replace('T', ' ') }}
                    <StatusChip
                        v-if="a.delegated"
                        status="info"
                        tone="info"
                        label="via delegation"
                    />
                    <StatusChip
                        v-if="a.owner_override"
                        status="pending"
                        label="owner override"
                    />
                    <span
                        v-if="a.reason"
                        class="text-surface-500"
                    > — {{ a.reason }}</span>
                </li>
            </ul>
        </Card>

        <Modal
            :open="rejectOpen"
            title="Return claim for correction"
            size="md"
            @close="rejectOpen = false"
        >
            <form
                id="reject-form"
                class="space-y-3"
                @submit.prevent="reject.post(route('finance.claims.reject', claim.id), { onSuccess: () => (rejectOpen = false) })"
            >
                <textarea
                    v-model="reject.reason"
                    data-autofocus
                    required
                    rows="2"
                    placeholder="What needs to change?"
                    aria-label="Reason"
                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                />
                <div
                    v-for="l in claim.lines ?? []"
                    :key="l.id"
                    class="text-sm"
                >
                    <label class="block text-surface-600">Line {{ l.line_no }} ({{ l.description }}) — optional note</label>
                    <input
                        v-model="reject.lines[l.id]"
                        class="w-full rounded-md border border-surface-300 px-3 py-1.5"
                    >
                </div>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="rejectOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="reject-form"
                    variant="danger"
                >
                    Return claim
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
