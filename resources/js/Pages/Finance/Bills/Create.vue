<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import type { Option } from '@/types/finance';

const props = defineProps<{ vendors: Option[]; categories: Option[] }>();
interface Line { description: string; quantity: string; unit_price: string; category_id: number | ''; capitalization_class: string }
const blank = (): Line => ({ description: '', quantity: '1', unit_price: '', category_id: '', capitalization_class: 'EXPENSE' });
const today = new Date().toISOString().slice(0, 10);
const form = useForm({ vendor_id: (props.vendors[0]?.id ?? '') as number | '', vendor_invoice_no: '', bill_date: today, due_date: today, description: '', lines: [blank()] as Line[] });
</script>

<template>
    <AppLayout
        title="Record bill"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bills', href: route('finance.bills.index') }, { label: 'New' }]"
    >
        <Card description="A vendor invoice can be recorded only once. Approval posts it to payables.">
            <p
                v-if="vendors.length === 0"
                class="text-sm"
            >
                Add a vendor first: <Link
                    :href="route('procurement.vendors.index')"
                    class="text-brand-700 underline"
                >
                    Vendors
                </Link>
            </p>
            <form
                v-else
                class="space-y-4"
                @submit.prevent="form.transform((d) => ({ ...d, lines: d.lines.map((l) => ({ ...l, category_id: l.category_id || null })) })).post(route('finance.bills.store'))"
            >
                <div class="grid gap-3 md:grid-cols-4">
                    <FormField
                        id="vendor_id"
                        label="Vendor"
                        :error="form.errors.vendor_id"
                    >
                        <select
                            id="vendor_id"
                            v-model="form.vendor_id"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                            <option
                                v-for="v in vendors"
                                :key="v.id"
                                :value="v.id"
                            >
                                {{ v.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="vendor_invoice_no"
                        label="Vendor invoice no."
                        :error="form.errors.vendor_invoice_no"
                    >
                        <input
                            id="vendor_invoice_no"
                            v-model="form.vendor_invoice_no"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="bill_date"
                        label="Invoice date"
                    >
                        <input
                            id="bill_date"
                            v-model="form.bill_date"
                            type="date"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="due_date"
                        label="Due date"
                        :error="form.errors.due_date"
                    >
                        <input
                            id="due_date"
                            v-model="form.due_date"
                            type="date"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-surface-500">
                        <tr>
                            <th class="py-1">
                                Description
                            </th><th class="w-24 py-1">
                                Qty
                            </th><th class="w-40 py-1">
                                Unit price
                            </th><th class="py-1">
                                Category
                            </th><th class="py-1">
                                Class
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
                            <td class="py-1 pr-2">
                                <input
                                    v-model="l.quantity"
                                    inputmode="decimal"
                                    :aria-label="`Line ${i + 1} quantity`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2 text-right"
                                >
                            </td>
                            <td class="py-1 pr-2">
                                <MoneyInput v-model="l.unit_price" />
                            </td>
                            <td class="py-1 pr-2">
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
                                    v-model="l.capitalization_class"
                                    :aria-label="`Line ${i + 1} class`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2"
                                >
                                    <option value="EXPENSE">
                                        Expense
                                    </option><option value="CONSUMABLE">
                                        Consumable
                                    </option><option value="LOW_VALUE_EQUIPMENT">
                                        Low-value equipment
                                    </option><option value="CAPITAL_ASSET">
                                        Capital asset
                                    </option>
                                </select>
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
                </table>
                <button
                    type="button"
                    class="text-sm font-medium text-brand-700"
                    @click="form.lines.push(blank())"
                >
                    + Add line
                </button>
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-600"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
                <div>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                    >
                        Save draft
                    </Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
