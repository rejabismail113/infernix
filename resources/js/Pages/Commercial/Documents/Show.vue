<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { DocDetail, DocRow, ReceiptRow } from '@/types/commercial';
import type { LinkedDoc } from '@/types/finance';

const props = defineProps<{
    type: string; label: string; routeBase: string; document: DocDetail;
    approvals: { level: number; approver: string; decision: string; owner_override: boolean; delegated: boolean; reason: string | null; decided_at: string }[];
    related: { receipts?: (ReceiptRow & { allocated: string })[]; credit_notes?: DocRow[]; source_quotation?: { id: number; number: string } | null; invoice?: { id: number; number: string; outstanding: string } | null; revisions?: DocRow[]; project_id?: number | null; invoices?: DocRow[] };
    journal: { id: number; no: string | null } | null; attachments: LinkedDoc[]; idempotencyKey: string;
    can: Record<'edit' | 'submit' | 'discard' | 'approve' | 'return' | 'issue' | 'respond' | 'revise' | 'convert' | 'convertProject' | 'void' | 'creditNote' | 'receipt' | 'regenerate' | 'attach', boolean>;
}>();
const d = computed(() => props.document);
const r = (name: string) => route(`commercial.${props.routeBase}.${name}`, d.value.id);
const plural = props.type === 'credit_note' ? 'Credit notes' : `${props.label}s`;
const approve = useForm({ override_reason: '' });
const issueDate = ref(new Date().toISOString().slice(0, 10));
const issuing = ref(false);
const voidOpen = ref(false);
const voidForm = useForm({ reason: '' });
const pdfUrl = (id: number | null) => (id ? route('documents.download', { document: id }) : null);
const cp = computed(() => (d.value.counterparty ?? {}) as Record<string, string | null>);

function post(name: string, data: Record<string, string | number | boolean> = {}) { router.post(r(name), data, { preserveScroll: true }); }
function ask(name: string, question: string) { const reason = window.prompt(question); if (reason) post(name, { reason }); }
function issue() {
    issuing.value = true;
    router.post(r('issue'), { issue_date: issueDate.value }, { headers: { 'Idempotency-Key': props.idempotencyKey }, preserveScroll: true, onFinish: () => { issuing.value = false; } });
}
function respond(accepted: boolean) {
    const note = window.prompt(accepted ? 'Client accepted — reference (PO no., email date…)?' : 'Why did the client reject it?');
    if (note !== null) router.post(route('commercial.quotations.respond', d.value.id), { accepted, note }, { preserveScroll: true });
}
const discard = () => { if (window.confirm('Discard this draft?')) router.delete(r('destroy')); };
const lineColumns = computed(() => [
    { key: 'line_no', label: '#', numeric: true }, { key: 'description', label: 'Description' }, { key: 'quantity', label: 'Qty', numeric: true }, { key: 'unit', label: 'Unit' },
    { key: 'unit_price', label: 'Unit price', numeric: true }, { key: 'discount', label: 'Discount', numeric: true }, { key: 'tax_amount', label: 'Tax', numeric: true }, { key: 'net_amount', label: 'Amount', numeric: true },
]);
</script>

<template>
    <AppLayout
        :title="`${label} ${d.number}`"
        :status="d.status"
        :breadcrumbs="[{ label: 'Sales' }, { label: plural, href: route(`commercial.${routeBase}.index`) }, { label: d.number }]"
    >
        <template #chips>
            <StatusChip
                v-if="d.settlement_status && d.status !== 'void'"
                :status="d.settlement_status === 'paid' ? 'paid' : 'pending'"
                :label="d.settlement_status.replace('_', ' ')"
            />
        </template>
        <template #actions>
            <a
                v-if="pdfUrl(d.pdf_document_id)"
                :href="pdfUrl(d.pdf_document_id) ?? undefined"
                class="inline-flex items-center gap-1.5 rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
                data-test="pdf"
            ><Icon
                name="download"
                :size="16"
            /> PDF</a>
            <a
                v-if="pdfUrl(d.void_pdf_document_id)"
                :href="pdfUrl(d.void_pdf_document_id) ?? undefined"
                class="inline-flex items-center gap-1.5 rounded-md border border-red-300 px-3 py-2 text-sm text-red-700 hover:bg-red-50"
            >VOID copy</a>
            <Link
                v-if="can.edit"
                :href="r('edit')"
                class="rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
            >
                Edit
            </Link>
            <Button
                v-if="can.submit"
                data-test="submit"
                @click="post('submit')"
            >
                Submit for approval
            </Button>
            <Button
                v-if="can.respond"
                data-test="accept"
                @click="respond(true)"
            >
                Client accepted
            </Button>
            <Button
                v-if="can.respond"
                variant="secondary"
                @click="respond(false)"
            >
                Client rejected
            </Button>
            <Button
                v-if="can.convertProject && !related.project_id"
                variant="secondary"
                data-test="convert-project"
                @click="router.post(route('commercial.quotations.convert-project', d.id))"
            >
                Create project
            </Button>
            <Button
                v-if="can.convert"
                data-test="convert-invoice"
                @click="router.post(route('commercial.quotations.convert-invoice', d.id))"
            >
                Create invoice
            </Button>
            <Link
                v-if="can.receipt"
                :href="route('commercial.receipts.create', { invoice_id: d.id })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                data-test="record-receipt"
            >
                Record receipt
            </Link>
            <Button
                v-if="can.creditNote"
                variant="secondary"
                @click="router.post(route('commercial.invoices.credit-note', d.id))"
            >
                Raise credit note
            </Button>
            <Button
                v-if="can.revise"
                variant="secondary"
                @click="router.post(route('commercial.quotations.revise', d.id))"
            >
                Revise
            </Button>
            <Button
                v-if="can.return"
                variant="secondary"
                @click="ask('return', 'Why return this to draft?')"
            >
                Return to draft
            </Button>
            <Button
                v-if="can.void"
                variant="secondary"
                data-test="void"
                @click="voidOpen = true"
            >
                Void
            </Button>
            <Button
                v-if="can.discard"
                variant="secondary"
                @click="discard"
            >
                Discard draft
            </Button>
        </template>

        <div
            v-if="d.status === 'void'"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"
        >
            VOID {{ d.voided_at?.slice(0, 10) }} — {{ d.void_reason }}. The number {{ d.document_no }} is kept and never reused.
        </div>
        <div
            v-if="can.regenerate"
            class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
        >
            The PDF has not been generated yet. <Button
                variant="secondary"
                @click="post('regenerate')"
            >
                Generate PDF
            </Button>
        </div>

        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                :title="d.title || label"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Client
                        </dt>
                        <dd class="font-medium">
                            {{ d.client }}
                        </dd>
                        <dd
                            v-if="cp.registration_no"
                            class="text-xs text-surface-500"
                        >
                            {{ cp.registration_no }}
                        </dd>
                    </div>
                    <div v-if="d.project">
                        <dt class="text-surface-500">
                            Project
                        </dt>
                        <dd>
                            <Link
                                :href="route('projects.projects.show', d.project.id)"
                                class="text-brand-700 hover:underline"
                            >
                                {{ d.project.code }} {{ d.project.name }}
                            </Link>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Dates
                        </dt>
                        <dd>
                            <span v-if="d.issue_date">Issued {{ d.issue_date }}</span><span v-else>Not issued</span>
                            <span v-if="d.due_date"> · due {{ d.due_date }}</span>
                            <span v-if="d.valid_until"> · valid until {{ d.valid_until }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Total
                        </dt>
                        <dd class="text-lg font-semibold">
                            <Amount :value="d.total" />
                        </dd>
                    </div>
                    <template v-if="type === 'invoice' && d.status !== 'draft'">
                        <div>
                            <dt class="text-surface-500">
                                Received / credited
                            </dt>
                            <dd>
                                <Amount :value="d.amount_received" /> / <Amount :value="d.amount_credited" />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-surface-500">
                                Outstanding
                            </dt>
                            <dd
                                class="text-lg font-semibold"
                                data-test="outstanding"
                            >
                                <Amount :value="d.outstanding ?? d.total" />
                            </dd>
                        </div>
                    </template>
                    <div v-if="related.source_quotation">
                        <dt class="text-surface-500">
                            From quotation
                        </dt>
                        <dd>
                            <Link
                                :href="route('commercial.quotations.show', related.source_quotation.id)"
                                class="font-mono text-brand-700 hover:underline"
                            >
                                {{ related.source_quotation.number }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="related.invoice">
                        <dt class="text-surface-500">
                            Credits invoice
                        </dt>
                        <dd>
                            <Link
                                :href="route('commercial.invoices.show', related.invoice.id)"
                                class="font-mono text-brand-700 hover:underline"
                            >
                                {{ related.invoice.number }}
                            </Link> · outstanding <Amount :value="related.invoice.outstanding" />
                        </dd>
                    </div>
                    <div v-if="related.project_id">
                        <dt class="text-surface-500">
                            Converted to project
                        </dt>
                        <dd>
                            <Link
                                :href="route('projects.projects.show', related.project_id)"
                                class="text-brand-700 hover:underline"
                            >
                                Open project
                            </Link>
                        </dd>
                    </div>
                    <div v-if="journal">
                        <dt class="text-surface-500">
                            Ledger
                        </dt>
                        <dd>
                            <Link
                                :href="route('finance.journals.show', journal.id)"
                                class="font-mono text-brand-700 hover:underline"
                            >
                                {{ journal.no }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="d.template">
                        <dt class="text-surface-500">
                            Template
                        </dt>
                        <dd>{{ d.template }}</dd>
                    </div>
                </dl>
            </Card>

            <Card
                v-if="can.approve"
                :title="`Approval · level ${d.current_level}`"
                description="The preparer cannot approve their own document. An Owner may, with a reason (audited)."
            >
                <form
                    class="space-y-2"
                    @submit.prevent="approve.post(r('approve'), { preserveScroll: true })"
                >
                    <input
                        v-model="approve.override_reason"
                        placeholder="Owner override reason (only if you prepared it)"
                        aria-label="Override reason"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <Button
                        type="submit"
                        data-test="approve"
                    >
                        Approve
                    </Button>
                </form>
            </Card>
            <Card
                v-else-if="can.issue"
                title="Issue"
                description="Issuing allocates the next number, freezes the client and company details and generates the PDF. It cannot be undone — only voided."
            >
                <div class="space-y-2">
                    <label class="block text-sm">Issue date <input
                        v-model="issueDate"
                        type="date"
                        class="mt-1 w-full rounded-md border border-surface-300 px-3 py-2"
                    ></label>
                    <Button
                        :disabled="issuing"
                        data-test="issue"
                        @click="issue"
                    >
                        Issue {{ label.toLowerCase() }}
                    </Button>
                </div>
            </Card>
            <Card
                v-else-if="approvals.length"
                title="Approvals"
            >
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="a in approvals"
                        :key="a.level"
                    >
                        L{{ a.level }} · {{ a.approver }} · {{ a.decided_at.slice(0, 16).replace('T', ' ') }}
                        <StatusChip
                            v-if="a.owner_override"
                            status="pending"
                            label="owner override"
                        />
                        <span
                            v-if="a.reason"
                            class="block text-xs text-surface-500"
                        >{{ a.reason }}</span>
                    </li>
                </ul>
            </Card>
        </div>

        <DataTable
            :columns="lineColumns"
            :rows="d.lines"
        >
            <template #cell-description="{ row }">
                <span class="whitespace-pre-line">{{ row.description }}</span>
            </template>
            <template #cell-unit_price="{ row }">
                <Amount :value="row.unit_price" />
            </template>
            <template #cell-discount="{ row }">
                <Amount
                    v-if="row.discount !== '0.00'"
                    :value="row.discount"
                />
            </template>
            <template #cell-tax_amount="{ row }">
                <Amount
                    v-if="row.tax_amount !== '0.00'"
                    :value="row.tax_amount"
                />
            </template>
            <template #cell-net_amount="{ row }">
                <Amount :value="row.net_amount" />
            </template>
            <template #footer>
                <tr class="border-t text-sm">
                    <td
                        colspan="7"
                        class="px-3 py-1 text-right text-surface-500"
                    >
                        Subtotal
                    </td><td class="px-3 py-1 text-right">
                        <Amount :value="d.subtotal" />
                    </td>
                </tr>
                <tr
                    v-if="d.tax_total !== '0.00'"
                    class="text-sm"
                >
                    <td
                        colspan="7"
                        class="px-3 py-1 text-right text-surface-500"
                    >
                        Tax
                    </td><td class="px-3 py-1 text-right">
                        <Amount :value="d.tax_total" />
                    </td>
                </tr>
                <tr class="font-semibold">
                    <td
                        colspan="7"
                        class="px-3 py-2 text-right"
                    >
                        Total
                    </td><td class="px-3 py-2 text-right">
                        <Amount :value="d.total" />
                    </td>
                </tr>
            </template>
        </DataTable>

        <div class="grid gap-5 xl:grid-cols-2">
            <Card
                v-if="related.receipts"
                title="Receipts"
            >
                <p
                    v-if="!related.receipts.length"
                    class="text-sm text-surface-500"
                >
                    No receipts yet.
                </p>
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="rc in related.receipts"
                        :key="rc.id"
                        class="flex items-center justify-between py-2"
                    >
                        <Link
                            :href="route('commercial.receipts.show', rc.id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ rc.receipt_no }}
                        </Link>
                        <span class="text-surface-500">{{ rc.receipt_date }}</span>
                        <StatusChip :status="rc.status" />
                        <Amount :value="rc.allocated" />
                    </li>
                </ul>
            </Card>
            <Card
                v-if="related.credit_notes?.length"
                title="Credit notes"
            >
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="c in related.credit_notes"
                        :key="c.id"
                        class="flex items-center justify-between py-2"
                    >
                        <Link
                            :href="route('commercial.credit-notes.show', c.id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ c.number }}
                        </Link>
                        <StatusChip :status="c.status" />
                        <Amount :value="c.total" />
                    </li>
                </ul>
            </Card>
            <Card
                v-if="related.revisions && related.revisions.length > 1"
                title="Revision history"
            >
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="rv in related.revisions"
                        :key="rv.id"
                        class="flex items-center justify-between py-2"
                    >
                        <Link
                            :href="route('commercial.quotations.show', rv.id)"
                            class="font-mono text-brand-700 hover:underline"
                            :class="{ 'font-bold': rv.id === d.id }"
                        >
                            {{ rv.number }}
                        </Link>
                        <StatusChip :status="rv.status" />
                        <Amount :value="rv.total" />
                    </li>
                </ul>
            </Card>
            <Card
                v-if="related.invoices?.length"
                title="Invoices from this quotation"
            >
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="iv in related.invoices"
                        :key="iv.id"
                        class="flex items-center justify-between py-2"
                    >
                        <Link
                            :href="route('commercial.invoices.show', iv.id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ iv.number }}
                        </Link>
                        <StatusChip :status="iv.status" />
                        <Amount :value="iv.total" />
                    </li>
                </ul>
            </Card>
            <Card
                v-if="d.terms || d.internal_notes"
                title="Terms & notes"
            >
                <p
                    v-if="d.terms"
                    class="whitespace-pre-line text-sm"
                >
                    {{ d.terms }}
                </p>
                <p
                    v-if="d.internal_notes"
                    class="mt-3 whitespace-pre-line rounded bg-amber-50 p-2 text-xs text-amber-900"
                >
                    Internal: {{ d.internal_notes }}
                </p>
            </Card>
            <Card :title="type === 'quotation' ? 'Client PO & attachments' : 'Attachments'">
                <EvidencePanel
                    :entity-type="type"
                    :entity-id="d.id"
                    :link-type="type === 'quotation' ? 'client_po' : 'evidence'"
                    :documents="attachments"
                    :can-attach="can.attach"
                />
            </Card>
        </div>

        <Modal
            :open="voidOpen"
            :title="`Void ${d.number}`"
            size="md"
            @close="voidOpen = false"
        >
            <p class="text-sm text-surface-600">
                The number is kept and marked VOID; any posting is reversed and a VOID copy is stored. Invoices with receipts or credit notes cannot be voided — issue a credit note instead.
            </p>
            <textarea
                v-model="voidForm.reason"
                rows="3"
                aria-label="Reason"
                placeholder="Reason (audited)"
                class="mt-3 w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
            />
            <template #footer>
                <Button
                    variant="secondary"
                    @click="voidOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="!voidForm.reason || voidForm.processing"
                    data-test="confirm-void"
                    @click="voidForm.post(r('void'), { preserveScroll: true, onSuccess: () => { voidOpen = false; } })"
                >
                    Void document
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
