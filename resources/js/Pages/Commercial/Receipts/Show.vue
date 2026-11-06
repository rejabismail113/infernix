<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import EvidencePanel from '@/Components/Finance/EvidencePanel.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ReceiptRow } from '@/types/commercial';
import type { LinkedDoc } from '@/types/finance';

defineProps<{
    receipt: ReceiptRow & { bank: string | null; notes: string | null };
    allocations: { invoice_id: number; number: string | null; amount: string; voided: boolean }[];
    journal: { id: number; no: string | null; reversal_id: number | null } | null; attachments: LinkedDoc[]; can: { void: boolean; regenerate: boolean };
}>();
const voidOpen = ref(false);
const voidForm = useForm({ reason: '' });
</script>

<template>
    <AppLayout
        :title="`Receipt ${receipt.receipt_no}`"
        :status="receipt.status"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Receipts', href: route('commercial.receipts.index') }, { label: receipt.receipt_no }]"
    >
        <template #actions>
            <a
                v-if="receipt.pdf_document_id"
                :href="route('documents.download', { document: receipt.pdf_document_id })"
                class="inline-flex items-center gap-1.5 rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
            ><Icon
                name="download"
                :size="16"
            /> Receipt PDF</a>
            <Button
                v-if="can.regenerate"
                variant="secondary"
                @click="router.post(route('commercial.receipts.regenerate', receipt.id))"
            >
                Generate PDF
            </Button>
            <Button
                v-if="can.void"
                variant="secondary"
                @click="voidOpen = true"
            >
                Void receipt
            </Button>
        </template>
        <div
            v-if="receipt.status === 'void'"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"
        >
            VOID — {{ receipt.void_reason }}. The posting was reversed and the invoices re-opened.
        </div>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Receipt"
                class="xl:col-span-2"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Received from
                        </dt><dd class="font-medium">
                            {{ receipt.payer_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Date
                        </dt><dd>{{ receipt.receipt_date }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Amount
                        </dt><dd class="text-lg font-semibold">
                            <Amount :value="receipt.amount" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Into
                        </dt><dd>{{ receipt.bank }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Method / reference
                        </dt><dd>{{ receipt.method.toUpperCase() }} {{ receipt.reference }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Unapplied
                        </dt><dd><Amount :value="receipt.unapplied_amount" /></dd>
                    </div>
                    <div v-if="journal">
                        <dt class="text-surface-500">
                            Ledger
                        </dt><dd>
                            <Link
                                :href="route('finance.journals.show', journal.id)"
                                class="font-mono text-brand-700 hover:underline"
                            >
                                {{ journal.no }}
                            </Link>
                            <span v-if="journal.reversal_id"> · reversed</span>
                        </dd>
                    </div>
                </dl>
            </Card>
            <Card title="Applied to">
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="a in allocations"
                        :key="a.invoice_id"
                        class="flex justify-between py-2"
                        :class="{ 'line-through opacity-60': a.voided }"
                    >
                        <Link
                            :href="route('commercial.invoices.show', a.invoice_id)"
                            class="font-mono text-brand-700 hover:underline"
                        >
                            {{ a.number }}
                        </Link>
                        <Amount :value="a.amount" />
                    </li>
                    <li
                        v-if="!allocations.length"
                        class="py-2 text-surface-500"
                    >
                        Not applied to an invoice (advance / unapplied).
                    </li>
                </ul>
                <StatusChip
                    class="mt-2"
                    :status="receipt.status"
                />
            </Card>
        </div>
        <Card title="Evidence (bank advice, cheque image)">
            <EvidencePanel
                entity-type="receipt"
                :entity-id="receipt.id"
                link-type="evidence"
                :documents="attachments"
                :can-attach="receipt.status === 'posted'"
            />
        </Card>
        <Modal
            :open="voidOpen"
            :title="`Void ${receipt.receipt_no}`"
            size="md"
            @close="voidOpen = false"
        >
            <p class="text-sm text-surface-600">
                Use this when the money did not arrive or was returned (e.g. a bounced cheque). The posting is reversed and the number kept.
            </p>
            <textarea
                v-model="voidForm.reason"
                rows="3"
                aria-label="Reason"
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
                    :disabled="!voidForm.reason"
                    @click="voidForm.post(route('commercial.receipts.void', receipt.id), { onSuccess: () => { voidOpen = false; } })"
                >
                    Void receipt
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
