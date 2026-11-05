<script setup lang="ts">
import { fieldError } from '@/lib/errors';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fromMinorUnits, isDecimalString, toMinorUnits } from '@/lib/money';
import type { Option } from '@/types/finance';

const props = defineProps<{ direction: 'inflow' | 'outflow'; banks: Option[]; categories: Option[]; costCentres: Option[]; vendors: Option[] }>();
interface Line { description: string; amount: string; category_id: number | ''; cost_centre_id: number | '' }
const blank = (): Line => ({ description: '', amount: '', category_id: '', cost_centre_id: '' });
const form = useForm({
    direction: props.direction, inflow_type: props.direction === 'inflow' ? 'sales_project' : '', payment_source: props.direction === 'outflow' ? 'DIRECT_COMPANY' : '',
    capitalization_class: props.direction === 'outflow' ? 'EXPENSE' : '', transaction_date: new Date().toISOString().slice(0, 10), bank_account_id: props.banks[0]?.id ?? '',
    payment_method: 'transfer', bank_reference: '', counterparty_name: '', vendor_id: '' as number | '', description: '', lines: [blank()] as Line[], post_now: true,
});
const total = computed(() => fromMinorUnits(form.lines.reduce((s, l) => s + (isDecimalString(l.amount) ? toMinorUnits(l.amount) : 0), 0)));
const needsBank = computed(() => form.direction === 'inflow' || form.payment_source === 'DIRECT_COMPANY');
const INFLOW: Record<string, string> = { sales_project: 'Project sale (no invoice)', sales_training: 'Training sale (no invoice)', grant: 'Grant', other_income: 'Other income', capital_injection: 'Owner capital injection (balance sheet)', loan: 'Loan received (balance sheet)', refund: 'Refund received (balance sheet)' };
const CLASS: Record<string, string> = { EXPENSE: 'Business expense', CONSUMABLE: 'Consumable', LOW_VALUE_EQUIPMENT: 'Low-value equipment', CAPITAL_ASSET: 'Capital asset (register in Assets)', OWNER_DRAWING: 'Owner drawing (personal, not deductible)' };
function submit() {
    form.transform((d) => ({ ...d, bank_account_id: needsBank.value ? d.bank_account_id : null, vendor_id: d.vendor_id || null, lines: d.lines.map((l) => ({ ...l, category_id: l.category_id || null, cost_centre_id: l.cost_centre_id || null })) })).post(route('finance.transactions.store'));
}
</script>

<template>
    <AppLayout
        :title="direction === 'inflow' ? 'Record income' : 'Record expense'"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: direction === 'inflow' ? 'Income' : 'Expenses', href: route('finance.transactions.index', { direction }) }, { label: 'New' }]"
    >
        <Card :description="direction === 'outflow' ? 'Staff-paid costs are submitted as claims, so they are reimbursed and counted once.' : 'Invoiced sales are received against the invoice (receipt), not here.'">
            <form
                class="space-y-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-3 md:grid-cols-3">
                    <FormField
                        v-if="direction === 'inflow'"
                        id="inflow_type"
                        label="Type of money received"
                        :error="form.errors.inflow_type"
                    >
                        <select
                            id="inflow_type"
                            v-model="form.inflow_type"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                            <option
                                v-for="(l, k) in INFLOW"
                                :key="k"
                                :value="k"
                            >
                                {{ l }}
                            </option>
                        </select>
                    </FormField>
                    <template v-else>
                        <FormField
                            id="payment_source"
                            label="Who paid?"
                            :error="form.errors.payment_source"
                        >
                            <select
                                id="payment_source"
                                v-model="form.payment_source"
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                                <option value="DIRECT_COMPANY">
                                    Company bank account
                                </option>
                                <option value="OWNER_PAID">
                                    Owner paid personally (business owes owner)
                                </option>
                            </select>
                        </FormField>
                        <FormField
                            id="capitalization_class"
                            label="Classification"
                            :error="form.errors.capitalization_class"
                        >
                            <select
                                id="capitalization_class"
                                v-model="form.capitalization_class"
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                                <option
                                    v-for="(l, k) in CLASS"
                                    :key="k"
                                    :value="k"
                                >
                                    {{ l }}
                                </option>
                            </select>
                        </FormField>
                    </template>
                    <FormField
                        id="transaction_date"
                        label="Date"
                        :error="form.errors.transaction_date"
                    >
                        <input
                            id="transaction_date"
                            v-model="form.transaction_date"
                            type="date"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        v-if="needsBank"
                        id="bank_account_id"
                        label="Bank account"
                        :error="form.errors.bank_account_id"
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
                        id="counterparty_name"
                        :label="direction === 'inflow' ? 'Received from' : 'Paid to'"
                    >
                        <input
                            id="counterparty_name"
                            v-model="form.counterparty_name"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="bank_reference"
                        label="Bank reference"
                    >
                        <input
                            id="bank_reference"
                            v-model="form.bank_reference"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="description"
                        label="Description"
                        class="md:col-span-3"
                    >
                        <input
                            id="description"
                            v-model="form.description"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-surface-500">
                        <tr>
                            <th class="py-1">
                                Line description
                            </th><th
                                v-if="direction === 'outflow'"
                                class="py-1"
                            >
                                Category
                            </th><th class="py-1">
                                Cost centre
                            </th><th class="w-44 py-1">
                                Amount
                            </th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(l, i) in form.lines"
                            :key="i"
                        >
                            <td class="py-1 pr-2">
                                <input
                                    v-model="l.description"
                                    required
                                    :aria-label="`Line ${i + 1} description`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2"
                                >
                            </td>
                            <td
                                v-if="direction === 'outflow'"
                                class="py-1 pr-2"
                            >
                                <select
                                    v-model="l.category_id"
                                    :aria-label="`Line ${i + 1} category`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2"
                                >
                                    <option value="">
                                        General
                                    </option>
                                    <option
                                        v-for="c in categories"
                                        :key="c.id"
                                        :value="c.id"
                                    >
                                        {{ c.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="py-1 pr-2">
                                <select
                                    v-model="l.cost_centre_id"
                                    :aria-label="`Line ${i + 1} cost centre`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2"
                                >
                                    <option value="">
                                        —
                                    </option>
                                    <option
                                        v-for="c in costCentres"
                                        :key="c.id"
                                        :value="c.id"
                                    >
                                        {{ c.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="py-1 pr-2">
                                <MoneyInput v-model="l.amount" />
                            </td>
                            <td>
                                <button
                                    v-if="form.lines.length > 1"
                                    type="button"
                                    class="text-red-700"
                                    :aria-label="`Remove line ${i + 1}`"
                                    @click="form.lines.splice(i, 1)"
                                >
                                    ✕
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t font-semibold">
                            <td :colspan="direction === 'outflow' ? 3 : 2">
                                <button
                                    type="button"
                                    class="py-2 text-sm font-medium text-brand-700"
                                    @click="form.lines.push(blank())"
                                >
                                    + Add line
                                </button>
                            </td>
                            <td class="py-2 text-right">
                                <Amount :value="total" />
                            </td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
                <label class="flex items-center gap-2 text-sm"><input
                    v-model="form.post_now"
                    type="checkbox"
                > Post to the ledger now (otherwise saved as draft)</label>
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-600"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing"
                >
                    Save
                </Button>
            </form>
        </Card>
    </AppLayout>
</template>
