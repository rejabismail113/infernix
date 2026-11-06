<script setup lang="ts">
/**
 * Purchase request with its full audit chain (M10-02): PR → approvals → vendor PO → goods / service receipts →
 * vendor bills → payments. Each step lives in its own module; this page only reads and links them.
 */
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import Stepper from '@/Components/Stepper.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import type { Option } from '@/types/operations';

interface PoLine { id: number; line_no: number; description: string; quantity: string; unit_price: string; amount: string }
interface Po { id: number; number: string | null; status: string; vendor_id: number | null; vendor: string | null; total: string; url: string; lines: PoLine[] }
interface Bill { id: number; number: string; vendor_invoice_no: string; date: string; amount: string; paid: string; status: string; url: string; payments: { id: number; number: string; date: string; amount: string; url: string }[] }
const props = defineProps<{
    request: { id: number; pr_no: string; title: string; status: string; estimated_total: string; needed_by: string | null; vendor: string | null; vendor_po_id: number | null; justification: string; rejection_reason: string | null; current_level: number; vendor_id: number | null; requested_by: number };
    lines: { line_no: number; description: string; quantity: string; estimated_unit_price: string; estimated_amount: string; capitalization_class: string }[];
    approvals: { level: number; name: string; decided_at: string; owner_override: boolean }[];
    po: Po | null; receipts: { id: number; grn_no: string; received_on: string; kind: string; status: string; lines: { description: string; quantity_ordered: string; quantity_received: string; condition: string | null }[]; bill_url: string | null }[];
    bills: Bill[] | null; vendors: Option[]; can: { edit: boolean; approve: boolean; order: boolean; receive: boolean };
}>();
const steps = [
    { key: 'request', label: 'Request' }, { key: 'approval', label: 'Approval' }, { key: 'po', label: 'Vendor PO' }, { key: 'receipt', label: 'Receipt' }, { key: 'bill', label: 'Bill' }, { key: 'payment', label: 'Payment' },
];
const current = (): string => {
    if (props.bills?.some((b) => b.status === 'paid')) return 'payment';
    if (props.bills?.length) return 'bill';
    if (props.receipts.length) return 'receipt';
    if (props.po) return 'po';
    if (['pending_approval', 'approved'].includes(props.request.status)) return 'approval';
    return 'request';
};
const act = (action: 'submit' | 'approve' | 'reject' | 'cancel') => {
    const data: Record<string, string> = {};
    if (action === 'reject' || action === 'cancel') { const r = window.prompt('Reason?'); if (!r) return; data.reason = r; }
    router.post(route(`procurement.requests.${action}`, props.request.id), data, { preserveScroll: true });
};
const vendorId = ref<number | ''>(props.request.vendor_id ?? '');
const order = () => router.post(route('procurement.requests.order', props.request.id), { vendor_id: vendorId.value });
const today = new Date().toISOString().slice(0, 10);
const rcv = useForm({ vendor_po_id: props.po?.id ?? 0, received_on: today, kind: 'goods', notes: '', lines: (props.po?.lines ?? []).map((l) => ({ po_line_id: l.id, quantity_received: l.quantity, condition: 'Good' })) });
const receive = () => rcv.post(route('procurement.receipts.store'), { preserveScroll: true });
</script>

<template>
    <AppLayout
        :title="`${request.pr_no} · ${request.title}`"
        :breadcrumbs="[{ label: 'Procurement' }, { label: 'Purchase requests', href: route('procurement.requests.index') }, { label: request.pr_no }]"
        :status="request.status"
    >
        <template #actions>
            <Button
                v-if="can.edit && ['draft', 'rejected'].includes(request.status)"
                data-test="submit-pr"
                @click="act('submit')"
            >
                Submit for approval
            </Button>
            <Button
                v-if="can.approve"
                data-test="approve-pr"
                @click="act('approve')"
            >
                Approve L{{ request.current_level }}
            </Button>
            <Button
                v-if="can.approve"
                variant="danger"
                @click="act('reject')"
            >
                Reject
            </Button>
            <Button
                v-if="can.edit && !['ordered', 'cancelled'].includes(request.status)"
                variant="secondary"
                @click="act('cancel')"
            >
                Cancel
            </Button>
        </template>
        <Stepper
            :steps="steps"
            :current="current()"
            class="mb-4"
        />
        <div class="grid gap-4 lg:grid-cols-3">
            <Card
                title="Request"
                class="lg:col-span-2"
            >
                <p class="whitespace-pre-line text-sm">
                    {{ request.justification }}
                </p>
                <p
                    v-if="request.rejection_reason"
                    class="mt-2 text-sm text-red-700"
                >
                    Rejected: {{ request.rejection_reason }}
                </p>
                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-surface-500">
                        <tr>
                            <th>#</th><th>Description</th><th>Qty</th><th class="text-right">
                                Est. unit
                            </th><th class="text-right">
                                Est. amount
                            </th><th>Class</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in lines"
                            :key="l.line_no"
                            class="border-t border-surface-100"
                        >
                            <td class="py-1">
                                {{ l.line_no }}
                            </td>
                            <td>{{ l.description }}</td>
                            <td>{{ l.quantity }}</td>
                            <td class="text-right font-mono">
                                <Amount :value="l.estimated_unit_price" />
                            </td>
                            <td class="text-right font-mono">
                                <Amount :value="l.estimated_amount" />
                            </td>
                            <td class="text-xs">
                                {{ l.capitalization_class.replace(/_/g, ' ').toLowerCase() }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t font-medium">
                            <td colspan="4">
                                Estimated total
                            </td><td class="text-right font-mono">
                                <Amount :value="request.estimated_total" />
                            </td><td />
                        </tr>
                    </tfoot>
                </table>
            </Card>
            <Card title="Approvals">
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="a in approvals"
                        :key="a.level"
                    >
                        L{{ a.level }} · {{ a.name }} · {{ String(a.decided_at).slice(0, 16) }} <span
                            v-if="a.owner_override"
                            class="text-amber-700"
                        >(owner override)</span>
                    </li>
                    <li
                        v-if="!approvals.length"
                        class="text-surface-500"
                    >
                        None yet.
                    </li>
                </ul>
                <div
                    v-if="can.order"
                    class="mt-4 space-y-2"
                >
                    <label
                        for="po-vendor"
                        class="block text-sm font-medium"
                    >Vendor for the PO</label>
                    <select
                        id="po-vendor"
                        v-model="vendorId"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                        <option value="">
                            —
                        </option>
                        <option
                            v-for="v in vendors"
                            :key="v.id"
                            :value="v.id"
                        >
                            {{ v.label }}
                        </option>
                    </select>
                    <Button
                        :disabled="!vendorId"
                        data-test="raise-po"
                        @click="order"
                    >
                        Raise vendor PO
                    </Button>
                </div>
            </Card>
        </div>

        <Card
            v-if="po"
            title="Vendor PO"
            class="mt-4"
        >
            <p class="text-sm">
                <a
                    :href="po.url"
                    class="font-medium text-brand-700 hover:underline"
                >{{ po.number ?? 'Draft PO' }}</a> · {{ po.vendor }} · <Amount :value="po.total" /> <StatusChip :status="po.status" />
                <span
                    v-if="po.status === 'draft'"
                    class="text-surface-500"
                > — submit, approve and issue it on the PO page.</span>
            </p>
        </Card>

        <Card
            v-if="po"
            title="Goods / service receipts"
            class="mt-4"
        >
            <div
                v-for="g in receipts"
                :key="g.id"
                class="mb-3 rounded-md border border-surface-100 p-2 text-sm"
            >
                <p class="font-medium">
                    {{ g.grn_no }} · {{ g.received_on }} · {{ g.kind }}
                    <a
                        v-if="g.bill_url"
                        :href="g.bill_url"
                        class="ml-2 text-brand-700 hover:underline"
                        :data-test="`bill-from-${g.id}`"
                    >Record vendor bill →</a>
                </p>
                <ul class="text-surface-600">
                    <li
                        v-for="(l, i) in g.lines"
                        :key="i"
                    >
                        {{ l.description }}: {{ l.quantity_received }} of {{ l.quantity_ordered }} {{ l.condition ? `(${l.condition})` : '' }}
                    </li>
                </ul>
            </div>
            <form
                v-if="can.receive"
                class="space-y-2"
                @submit.prevent="receive"
            >
                <div class="flex flex-wrap gap-2 text-sm">
                    <input
                        v-model="rcv.received_on"
                        type="date"
                        aria-label="Received on"
                        class="rounded border border-surface-300 px-2 py-1"
                    >
                    <select
                        v-model="rcv.kind"
                        aria-label="Kind"
                        class="rounded border border-surface-300 px-2 py-1"
                    >
                        <option value="goods">
                            Goods
                        </option>
                        <option value="service">
                            Service
                        </option>
                    </select>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="(l, i) in rcv.lines"
                            :key="l.po_line_id"
                        >
                            <td class="py-1">
                                {{ po.lines[i]?.description }} <span class="text-surface-500">(ordered {{ po.lines[i]?.quantity }})</span>
                            </td>
                            <td class="w-28">
                                <input
                                    v-model="l.quantity_received"
                                    inputmode="decimal"
                                    :aria-label="`Received line ${i + 1}`"
                                    class="w-full rounded border border-surface-300 px-2 py-1"
                                >
                            </td>
                            <td class="w-32">
                                <input
                                    v-model="l.condition"
                                    :aria-label="`Condition line ${i + 1}`"
                                    class="w-full rounded border border-surface-300 px-2 py-1"
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="fieldError(rcv.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(rcv.errors, 'general') }}
                </p>
                <Button
                    type="submit"
                    data-test="record-receipt"
                    :disabled="rcv.processing"
                >
                    Record receipt
                </Button>
            </form>
        </Card>

        <Card
            v-if="bills"
            title="Vendor bills & payments"
            class="mt-4"
        >
            <ul class="space-y-2 text-sm">
                <li
                    v-for="b in bills"
                    :key="b.id"
                >
                    <a
                        :href="b.url"
                        class="font-medium text-brand-700 hover:underline"
                    >{{ b.number }}</a> · invoice {{ b.vendor_invoice_no }} · {{ b.date }} · <Amount :value="b.amount" /> (paid <Amount :value="b.paid" />) <StatusChip :status="b.status" />
                    <ul class="ml-4 text-surface-600">
                        <li
                            v-for="p in b.payments"
                            :key="p.id"
                        >
                            Payment <a
                                :href="p.url"
                                class="text-brand-700 hover:underline"
                            >{{ p.number }}</a> {{ p.date }} · <Amount :value="p.amount" />
                        </li>
                    </ul>
                </li>
                <li
                    v-if="!bills.length"
                    class="text-surface-500"
                >
                    No bill recorded against this PO yet.
                </li>
            </ul>
        </Card>
    </AppLayout>
</template>
