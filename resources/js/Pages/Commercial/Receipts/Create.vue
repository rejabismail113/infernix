<script setup lang="ts">
/** Record cash actually received; saving IS the posting (Dr Bank / Cr AR). Idempotent submit (§5.1). M03-04 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { allocateOldestFirst, compareDecimals, subtractDecimals, sumDecimals } from '@/lib/decimal';
import { fieldError } from '@/lib/errors';
import type { PickOption } from '@/types/commercial';

interface OpenInvoice { id: number; number: string; client_id: number | null; client: string; due_date: string | null; outstanding: string }
const props = defineProps<{ banks: PickOption[]; clients: PickOption[]; openInvoices: OpenInvoice[]; preselect: number | null; idempotencyKey: string }>();
const pre = props.openInvoices.find((i) => i.id === props.preselect);
const form = useForm({
    receipt_date: new Date().toISOString().slice(0, 10), bank_account_id: (props.banks[0]?.id ?? '') as number | '', amount: (pre?.outstanding ?? '') as string | number | null,
    method: 'transfer', reference: '', client_id: (pre?.client_id ?? '') as number | '', payer_name: '', notes: '',
    allocations: {} as Record<number, string | number | null>,
});
const invoices = computed(() => props.openInvoices.filter((i) => !form.client_id || i.client_id === form.client_id));
if (pre) form.allocations[pre.id] = pre.outstanding;
watch(() => form.client_id, () => { form.allocations = {}; });
const allocated = computed(() => sumDecimals(Object.values(form.allocations).map((v) => String(v || '0'))));
const unapplied = computed(() => subtractDecimals(String(form.amount || '0'), allocated.value));
const over = computed(() => compareDecimals(allocated.value, String(form.amount || '0')) > 0);
function auto() {
    const list = invoices.value;
    const amounts = allocateOldestFirst(String(form.amount || '0'), list.map((i) => i.outstanding));
    form.allocations = Object.fromEntries(list.map((i, k) => [i.id, amounts[k] === '0.00' ? '' : amounts[k]]));
}
function submit() {
    form.transform((d) => ({
        ...d, amount: String(d.amount ?? ''), client_id: d.client_id || null, _idempotency_key: props.idempotencyKey,
        allocations: Object.entries(d.allocations).filter(([, v]) => v !== '' && v !== null && compareDecimals(String(v), '0') > 0).map(([id, v]) => ({ invoice_id: Number(id), amount: String(v) })),
    })).post(route('commercial.receipts.store'), { headers: { 'Idempotency-Key': props.idempotencyKey } });
}
</script>

<template>
    <AppLayout
        title="Record receipt"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Receipts', href: route('commercial.receipts.index') }, { label: 'New' }]"
    >
        <form
            class="space-y-5"
            @submit.prevent="submit"
        >
            <Card
                title="Money received"
                description="Only record money that has actually arrived. Saving posts it to the bank and receivables immediately and issues the official receipt."
            >
                <div class="grid gap-4 md:grid-cols-4">
                    <FormField
                        id="receipt_date"
                        label="Date received"
                        :error="form.errors.receipt_date"
                    >
                        <input
                            id="receipt_date"
                            v-model="form.receipt_date"
                            type="date"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                    <FormField
                        id="bank_account_id"
                        label="Into bank account"
                        :error="form.errors.bank_account_id"
                    >
                        <select
                            id="bank_account_id"
                            v-model="form.bank_account_id"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                            <option
                                v-for="b in banks"
                                :key="b.id"
                                :value="b.id"
                            >
                                {{ b.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="amount"
                        label="Amount (MYR)"
                        :error="form.errors.amount"
                    >
                        <MoneyInput
                            id="amount"
                            v-model="form.amount"
                        />
                    </FormField>
                    <FormField
                        id="method"
                        label="Method"
                        :error="form.errors.method"
                    >
                        <select
                            id="method"
                            v-model="form.method"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                            <option
                                v-for="m in ['transfer', 'duitnow', 'fpx', 'cheque', 'cash', 'card']"
                                :key="m"
                                :value="m"
                            >
                                {{ m.toUpperCase() }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="client_id"
                        label="Client"
                        :error="form.errors.client_id"
                    >
                        <select
                            id="client_id"
                            v-model="form.client_id"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                            <option value="">
                                — not a client —
                            </option>
                            <option
                                v-for="c in clients"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        v-if="!form.client_id"
                        id="payer_name"
                        label="Received from"
                        :error="form.errors.payer_name"
                    >
                        <input
                            id="payer_name"
                            v-model="form.payer_name"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                    <FormField
                        id="reference"
                        label="Bank reference"
                        :error="form.errors.reference"
                    >
                        <input
                            id="reference"
                            v-model="form.reference"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                </div>
            </Card>
            <Card title="Apply to invoices">
                <template #actions>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="auto"
                    >
                        Allocate oldest first
                    </Button>
                </template>
                <p
                    v-if="!invoices.length"
                    class="text-sm text-surface-500"
                >
                    No open invoices{{ form.client_id ? ' for this client' : '' }}. The full amount will be held as an unapplied receipt.
                </p>
                <table
                    v-else
                    class="w-full text-sm"
                >
                    <thead class="text-left text-xs uppercase text-surface-500">
                        <tr>
                            <th class="py-2">
                                Invoice
                            </th><th>Client</th><th>Due</th><th class="text-right">
                                Outstanding
                            </th><th class="w-40 pl-3">
                                Apply
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="i in invoices"
                            :key="i.id"
                            class="border-t border-surface-100"
                        >
                            <td class="py-2 font-mono">
                                {{ i.number }}
                            </td><td>{{ i.client }}</td><td>{{ i.due_date }}</td>
                            <td class="text-right">
                                <Amount :value="i.outstanding" />
                            </td>
                            <td class="py-1 pl-3">
                                <MoneyInput
                                    v-model="form.allocations[i.id]"
                                    :invalid="compareDecimals(String(form.allocations[i.id] || '0'), i.outstanding) > 0"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <dl class="mt-3 flex flex-wrap justify-end gap-6 text-sm">
                    <div>
                        <dt class="text-surface-500">
                            Applied
                        </dt><dd><Amount :value="allocated" /></dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Unapplied
                        </dt><dd :class="over ? 'text-red-700' : ''">
                            <Amount :value="unapplied" />
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="over"
                    class="mt-2 text-sm text-red-700"
                >
                    You cannot apply more than the amount received.
                </p>
            </Card>
            <p
                v-if="fieldError(form.errors, 'general')"
                class="rounded-md bg-red-50 p-3 text-sm text-red-800"
            >
                {{ fieldError(form.errors, 'general') }}
            </p>
            <Button
                type="submit"
                :disabled="form.processing || over || !form.amount"
                data-test="post-receipt"
            >
                Post receipt
            </Button>
        </form>
    </AppLayout>
</template>
