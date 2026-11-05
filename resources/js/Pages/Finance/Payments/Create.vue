<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { fromMinorUnits, isDecimalString, toMinorUnits } from '@/lib/money';
import type { Option } from '@/types/finance';

interface OpenItem { target_type: 'bill' | 'claim'; target_id: number; reference: string; payee_type: string; payee_id: number; payee_name: string; due_date: string | null; unallocated: string }
const props = defineProps<{ banks: Option[]; openItems: OpenItem[]; preselect: { target_type: string | null; target_id: number | null }; idempotencyKey: string }>();
const key = (i: OpenItem) => `${i.target_type}:${i.target_id}`;
const amounts = reactive<Record<string, string>>({});
const selected = reactive<Record<string, boolean>>({});
for (const i of props.openItems) {
    amounts[key(i)] = i.unallocated;
    selected[key(i)] = props.preselect.target_type === i.target_type && props.preselect.target_id === i.target_id;
}
const chosen = computed(() => props.openItems.filter((i) => selected[key(i)]));
const payee = computed(() => chosen.value[0] ?? null);
const mixedPayees = computed(() => new Set(chosen.value.map((i) => `${i.payee_type}:${i.payee_id}`)).size > 1);
const total = computed(() => fromMinorUnits(chosen.value.reduce((s, i) => s + (isDecimalString(amounts[key(i)]) ? toMinorUnits(amounts[key(i)]) : 0), 0)));
const form = useForm({ payment_date: new Date().toISOString().slice(0, 10), bank_account_id: props.banks[0]?.id ?? '', method: 'transfer', reference: '', memo: '', _idempotency_key: props.idempotencyKey });
function submit() {
    if (!payee.value || mixedPayees.value) return;
    const p = payee.value;
    form.transform((d) => ({ ...d, payee_type: p.payee_type, payee_id: p.payee_id, payee_name: p.payee_name, allocations: chosen.value.map((i) => ({ target_type: i.target_type, target_id: i.target_id, amount: amounts[key(i)] })) }))
        .post(route('finance.payments.store'));
}
</script>

<template>
    <AppLayout
        title="New payment voucher"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Payments', href: route('finance.payments.index') }, { label: 'New' }]"
    >
        <Card
            title="1. What are you paying?"
            description="Pick approved bills or claims for one payee. Partial amounts are allowed; nothing can be paid beyond its balance."
        >
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-surface-500">
                        <tr>
                            <th class="w-8" /><th class="py-1">
                                Item
                            </th><th>Payee</th><th>Due</th><th class="text-right">
                                Still owed
                            </th><th class="w-44 text-right">
                                Pay now
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="i in openItems"
                            :key="key(i)"
                            class="border-t border-surface-100"
                        >
                            <td class="py-2">
                                <input
                                    v-model="selected[key(i)]"
                                    type="checkbox"
                                    :aria-label="`Select ${i.reference}`"
                                >
                            </td>
                            <td class="py-2">
                                <span class="rounded bg-surface-100 px-1.5 text-xs">{{ i.target_type }}</span> {{ i.reference }}
                            </td>
                            <td>{{ i.payee_name }}</td>
                            <td>{{ i.due_date ?? '—' }}</td>
                            <td class="text-right">
                                <Amount :value="i.unallocated" />
                            </td>
                            <td class="py-1 pl-2">
                                <MoneyInput
                                    v-model="amounts[key(i)]"
                                    :disabled="!selected[key(i)]"
                                />
                            </td>
                        </tr>
                        <tr v-if="openItems.length === 0">
                            <td
                                colspan="6"
                                class="py-6 text-center text-surface-500"
                            >
                                Nothing is approved and unpaid.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="mixedPayees"
                class="mt-2 text-sm text-red-600"
                role="alert"
            >
                One voucher pays one payee — select items for a single payee.
            </p>
        </Card>
        <Card title="2. How is it paid?">
            <form
                class="grid gap-3 md:grid-cols-4"
                @submit.prevent="submit"
            >
                <FormField
                    id="payment_date"
                    label="Payment date"
                >
                    <input
                        id="payment_date"
                        v-model="form.payment_date"
                        type="date"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="bank_account_id"
                    label="From bank account"
                >
                    <select
                        id="bank_account_id"
                        v-model="form.bank_account_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
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
                    id="method"
                    label="Method"
                >
                    <select
                        id="method"
                        v-model="form.method"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="transfer">
                            Bank transfer
                        </option><option value="duitnow">
                            DuitNow
                        </option><option value="cheque">
                            Cheque
                        </option><option value="cash">
                            Cash
                        </option><option value="card">
                            Card
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="reference"
                    label="Reference"
                >
                    <input
                        id="reference"
                        v-model="form.reference"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="memo"
                    label="Memo"
                    class="md:col-span-4"
                >
                    <input
                        id="memo"
                        v-model="form.memo"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <div class="flex items-center gap-4 md:col-span-4">
                    <Button
                        type="submit"
                        :disabled="form.processing || !payee || mixedPayees"
                    >
                        Create voucher
                    </Button>
                    <span class="text-sm">Pay <strong>{{ payee?.payee_name ?? '—' }}</strong> <Amount :value="total" /></span>
                </div>
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-600 md:col-span-4"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
            </form>
        </Card>
    </AppLayout>
</template>
