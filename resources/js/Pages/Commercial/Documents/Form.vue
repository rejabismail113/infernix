<script setup lang="ts">
/**
 * Quotation / invoice / credit note builder (M03-01, M03-03). Totals preview uses exact decimal maths
 * (lib/decimal — never floats); the server recomputes everything on save.
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import PickerModal, { type PickerItem } from '@/Components/PickerModal.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { lineAmounts, sumDecimals } from '@/lib/decimal';
import { fieldError } from '@/lib/errors';
import type { DocDetail, DocLine, PickOption, TaxCode } from '@/types/commercial';

const props = defineProps<{
    type: string; label: string; routeBase: string; document: DocDetail | null;
    prefill: { client_id: number | null; project_id: number | null; title: string; lead_id?: number; lines: Partial<DocLine>[] } | null;
    clients: PickOption[]; vendors: PickOption[]; projects: PickOption[]; taxCodes: TaxCode[]; revenueAccounts: PickOption[]; defaultRevenueAccountId: number | null; canCreateClient: boolean;
}>();

interface LineForm { description: string; quantity: string; unit: string; unit_price: string | number | null; discount: string | number | null; tax_code_id: number | ''; revenue_account_id: number | ''; source_line_id: number | null; milestone_id: number | null }
const toLine = (l?: Partial<DocLine>): LineForm => ({
    description: l?.description ?? '', quantity: l?.quantity ?? '1', unit: l?.unit ?? '', unit_price: l?.unit_price ?? '', discount: l?.discount && l.discount !== '0.00' ? l.discount : '',
    tax_code_id: l?.tax_code_id ?? '', revenue_account_id: l?.revenue_account_id ?? (props.defaultRevenueAccountId ?? ''), source_line_id: l?.source_line_id ?? null,
    milestone_id: l?.milestone_id ?? null,
});
const doc = props.document;
const isQuote = props.type === 'quotation';
const isCredit = props.type === 'credit_note';
const isVendor = props.type === 'vendor_po';
const noRevenue = isQuote || isVendor;
const in30 = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
const form = useForm({
    client_id: (doc?.client_id ?? props.prefill?.client_id ?? '') as number | '',
    vendor_id: (isVendor ? (doc?.counterparty_id ?? '') : '') as number | '',
    lead_id: (props.prefill?.lead_id ?? null) as number | null,
    project_id: (doc?.project_id ?? props.prefill?.project_id ?? '') as number | '',
    title: doc?.title ?? props.prefill?.title ?? '',
    valid_until: doc?.valid_until ?? (isQuote ? in30 : ''),
    due_date: doc?.due_date ?? '',
    terms: doc?.terms ?? '',
    internal_notes: doc?.internal_notes ?? '',
    lines: (doc?.lines ?? props.prefill?.lines ?? [{}]).map((l) => toLine(l)),
});

const rate = (id: number | '') => props.taxCodes.find((t) => t.id === id)?.rate ?? '0';
const previews = computed(() => form.lines.map((l) => lineAmounts(String(l.quantity || '0'), String(l.unit_price || '0'), String(l.discount || '0'), rate(l.tax_code_id))));
const totals = computed(() => ({
    subtotal: sumDecimals(previews.value.map((p) => p.net)), tax: sumDecimals(previews.value.map((p) => p.tax)), total: sumDecimals(previews.value.map((p) => p.total)),
}));
const parties = computed(() => (isVendor ? props.vendors : props.clients));
const clientLabel = computed(() => (isVendor ? props.vendors.find((v) => v.id === form.vendor_id)?.label : props.clients.find((c) => c.id === form.client_id)?.label) ?? (doc?.client || ''));
const projectOptions = computed(() => props.projects.filter((p) => !form.client_id || p.client_id === form.client_id));

const picking = ref(false);
function pick(item: PickerItem) {
    if (isVendor) form.vendor_id = Number(item.id); else { form.client_id = Number(item.id); form.project_id = ''; }
    picking.value = false;
}

const newClient = ref(false);
const clientForm = useForm({ name: '', registration_no: '', email: '', phone: '', contact_person: '', payment_terms_days: 30, address: { line1: '', city: '', postcode: '', state: '' } });
function saveClient() {
    clientForm.post(route('projects.clients.store'), {
        preserveScroll: true, preserveState: true,
        onSuccess: () => router.reload({ only: ['clients'], onSuccess: () => {
            const created = [...props.clients].filter((c) => c.label === clientForm.name).sort((a, b) => b.id - a.id)[0];
            if (created) form.client_id = created.id;
            newClient.value = false; clientForm.reset();
        } }),
    });
}

function submit() {
    const t = form.transform((d) => ({
        ...d, client_id: isVendor ? undefined : (d.client_id || null), vendor_id: isVendor ? (d.vendor_id || null) : undefined, lead_id: isQuote && !doc ? d.lead_id : undefined,
        project_id: d.project_id || null, valid_until: d.valid_until || null, due_date: d.due_date || null,
        lines: d.lines.map((l) => ({ ...l, unit_price: String(l.unit_price ?? ''), discount: l.discount === '' || l.discount === null ? null : String(l.discount),
            tax_code_id: l.tax_code_id || null, revenue_account_id: noRevenue ? null : (l.revenue_account_id || null), unit: l.unit || null })),
    }));
    if (doc) t.put(route(`commercial.${props.routeBase}.update`, doc.id)); else t.post(route(`commercial.${props.routeBase}.store`));
}
const title = computed(() => (doc ? `Edit ${doc.number}` : `New ${props.label.toLowerCase()}`));
const plural = props.type === 'credit_note' ? 'Credit notes' : isVendor ? 'Vendor POs' : `${props.label}s`;
</script>

<template>
    <AppLayout
        :title="title"
        :status="doc?.status"
        :breadcrumbs="[{ label: 'Sales' }, { label: plural, href: route(`commercial.${routeBase}.index`) }, ...(doc ? [{ label: doc.number, href: route(`commercial.${routeBase}.show`, doc.id) }] : []), { label: doc ? 'Edit' : 'New' }]"
    >
        <form
            class="space-y-5"
            @submit.prevent="submit"
        >
            <Card :title="isCredit ? 'Credit note' : `${label} details`">
                <div class="grid gap-4 md:grid-cols-3">
                    <FormField
                        id="client"
                        :label="isVendor ? 'Vendor' : 'Client'"
                        :error="form.errors.client_id || (form.errors as Record<string, string>).vendor_id"
                    >
                        <div
                            v-if="isCredit"
                            class="rounded-md border border-surface-200 bg-surface-50 px-3 py-2 text-sm"
                        >
                            {{ doc?.client }}
                        </div>
                        <div
                            v-else
                            class="flex gap-2"
                        >
                            <button
                                id="client"
                                type="button"
                                class="flex-1 truncate rounded-md border border-surface-300 px-3 py-2 text-left text-sm hover:border-brand-400"
                                data-test="pick-client"
                                @click="picking = true"
                            >
                                {{ clientLabel || (isVendor ? 'Choose vendor…' : 'Choose client…') }}
                            </button>
                            <Button
                                v-if="canCreateClient && !isVendor"
                                type="button"
                                variant="secondary"
                                title="New client"
                                @click="newClient = true"
                            >
                                <Icon
                                    name="plus"
                                    :size="16"
                                />
                            </Button>
                        </div>
                    </FormField>
                    <FormField
                        id="project_id"
                        label="Project (optional)"
                        :error="form.errors.project_id"
                    >
                        <select
                            id="project_id"
                            v-model="form.project_id"
                            :disabled="isCredit"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                            <option value="">
                                —
                            </option>
                            <option
                                v-for="p in projectOptions"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="title"
                        label="Title"
                        :error="form.errors.title"
                    >
                        <input
                            id="title"
                            v-model="form.title"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                            placeholder="e.g. Leadership workshop, March"
                        >
                    </FormField>
                    <FormField
                        v-if="isQuote"
                        id="valid_until"
                        label="Valid until"
                        :error="form.errors.valid_until"
                    >
                        <input
                            id="valid_until"
                            v-model="form.valid_until"
                            type="date"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                    <FormField
                        v-if="type === 'invoice'"
                        id="due_date"
                        label="Due date (blank = client terms from issue date)"
                        :error="form.errors.due_date"
                    >
                        <input
                            id="due_date"
                            v-model="form.due_date"
                            type="date"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                </div>
            </Card>

            <Card
                title="Lines"
                description="Amounts in MYR. Discount is an amount off the line. Tax codes stay inactive until SST registration is configured."
            >
                <p
                    v-if="fieldError(form.errors, 'lines')"
                    class="mb-2 text-sm text-red-700"
                >
                    {{ fieldError(form.errors, 'lines') }}
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead class="text-left text-xs uppercase text-surface-500">
                            <tr>
                                <th class="w-8 py-2">
                                    #
                                </th><th>Description</th><th class="w-24">
                                    Qty
                                </th><th class="w-24">
                                    Unit
                                </th><th class="w-32">
                                    Unit price
                                </th><th class="w-28">
                                    Discount
                                </th><th class="w-36">
                                    Tax
                                </th><th
                                    v-if="!noRevenue"
                                    class="w-44"
                                >
                                    Revenue account
                                </th><th class="w-28 text-right">
                                    Amount
                                </th><th class="w-8" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(l, i) in form.lines"
                                :key="i"
                                class="border-t border-surface-100 align-top"
                                :data-test="`line-${i}`"
                            >
                                <td class="py-2 text-surface-500">
                                    {{ i + 1 }}
                                </td>
                                <td class="py-2 pr-2">
                                    <textarea
                                        v-model="l.description"
                                        rows="2"
                                        :aria-label="`Line ${i + 1} description`"
                                        class="w-full rounded-md border border-surface-300 px-2 py-1.5"
                                        :class="{ 'border-red-400': fieldError(form.errors, `lines.${i}.description`) }"
                                    />
                                </td>
                                <td class="py-2 pr-2">
                                    <input
                                        v-model="l.quantity"
                                        inputmode="decimal"
                                        :aria-label="`Line ${i + 1} quantity`"
                                        class="w-full rounded-md border border-surface-300 px-2 py-1.5 text-right"
                                        :class="{ 'border-red-400': fieldError(form.errors, `lines.${i}.quantity`) }"
                                    >
                                </td>
                                <td class="py-2 pr-2">
                                    <input
                                        v-model="l.unit"
                                        :aria-label="`Line ${i + 1} unit`"
                                        placeholder="day, pax…"
                                        class="w-full rounded-md border border-surface-300 px-2 py-1.5"
                                    >
                                </td>
                                <td class="py-2 pr-2">
                                    <MoneyInput
                                        v-model="l.unit_price"
                                        :invalid="!!fieldError(form.errors, `lines.${i}.unit_price`)"
                                    />
                                </td>
                                <td class="py-2 pr-2">
                                    <MoneyInput
                                        v-model="l.discount"
                                        placeholder="0.00"
                                    />
                                </td>
                                <td class="py-2 pr-2">
                                    <select
                                        v-model="l.tax_code_id"
                                        :aria-label="`Line ${i + 1} tax`"
                                        class="w-full rounded-md border border-surface-300 px-2 py-1.5"
                                    >
                                        <option value="">
                                            No tax
                                        </option>
                                        <option
                                            v-for="t in taxCodes"
                                            :key="t.id"
                                            :value="t.id"
                                        >
                                            {{ t.label }}
                                        </option>
                                    </select>
                                </td>
                                <td
                                    v-if="!noRevenue"
                                    class="py-2 pr-2"
                                >
                                    <select
                                        v-model="l.revenue_account_id"
                                        :aria-label="`Line ${i + 1} revenue account`"
                                        class="w-full rounded-md border border-surface-300 px-2 py-1.5"
                                    >
                                        <option
                                            v-for="a in revenueAccounts"
                                            :key="a.id"
                                            :value="a.id"
                                        >
                                            {{ a.label }}
                                        </option>
                                    </select>
                                </td>
                                <td class="py-2 text-right font-medium">
                                    <Amount :value="previews[i].net" />
                                </td>
                                <td class="py-2 pl-1">
                                    <button
                                        v-if="form.lines.length > 1"
                                        type="button"
                                        class="rounded p-1 text-surface-400 hover:bg-red-50 hover:text-red-600"
                                        :aria-label="`Remove line ${i + 1}`"
                                        @click="form.lines.splice(i, 1)"
                                    >
                                        <Icon
                                            name="close"
                                            :size="16"
                                        />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                    <Button
                        type="button"
                        variant="secondary"
                        data-test="add-line"
                        @click="form.lines.push(toLine())"
                    >
                        <Icon
                            name="plus"
                            :size="16"
                        /> Add line
                    </Button>
                    <dl class="grid min-w-[260px] grid-cols-2 gap-y-1 text-sm">
                        <dt class="text-surface-500">
                            Subtotal
                        </dt><dd class="text-right">
                            <Amount :value="totals.subtotal" />
                        </dd>
                        <dt class="text-surface-500">
                            Tax
                        </dt><dd class="text-right">
                            <Amount :value="totals.tax" />
                        </dd>
                        <dt class="border-t pt-1 font-semibold">
                            Total
                        </dt><dd
                            class="border-t pt-1 text-right text-lg font-semibold"
                            data-test="total"
                        >
                            <Amount :value="totals.total" />
                        </dd>
                    </dl>
                </div>
            </Card>

            <Card title="Terms & notes">
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField
                        id="terms"
                        label="Terms & conditions (printed)"
                        :error="form.errors.terms"
                    >
                        <textarea
                            id="terms"
                            v-model="form.terms"
                            rows="5"
                            placeholder="Blank = template default terms"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="internal_notes"
                        label="Internal notes (never printed)"
                        :error="form.errors.internal_notes"
                    >
                        <textarea
                            id="internal_notes"
                            v-model="form.internal_notes"
                            rows="5"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        />
                    </FormField>
                </div>
            </Card>
            <p
                v-if="fieldError(form.errors, 'general')"
                class="rounded-md bg-red-50 p-3 text-sm text-red-800"
            >
                {{ fieldError(form.errors, 'general') }}
            </p>
            <div class="flex gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save"
                >
                    Save draft
                </Button>
                <Link
                    :href="doc ? route(`commercial.${routeBase}.show`, doc.id) : route(`commercial.${routeBase}.index`)"
                    class="rounded-md px-4 py-2 text-sm text-surface-600 hover:bg-surface-100"
                >
                    Cancel
                </Link>
            </div>
        </form>

        <PickerModal
            :open="picking"
            :title="isVendor ? 'Choose vendor' : 'Choose client'"
            :items="parties.map((c) => ({ id: c.id, label: c.label, meta: c.meta }))"
            local-filter
            @close="picking = false"
            @select="pick"
        />
        <Modal
            :open="newClient"
            title="New client"
            size="md"
            @close="newClient = false"
        >
            <form
                id="client-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="saveClient"
            >
                <FormField
                    id="c-name"
                    label="Name"
                    :error="clientForm.errors.name"
                    class="col-span-2"
                >
                    <input
                        id="c-name"
                        v-model="clientForm.name"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-reg"
                    label="SSM / reg. no."
                >
                    <input
                        id="c-reg"
                        v-model="clientForm.registration_no"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-terms"
                    label="Payment terms (days)"
                >
                    <input
                        id="c-terms"
                        v-model.number="clientForm.payment_terms_days"
                        type="number"
                        min="0"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-email"
                    label="Email"
                    :error="clientForm.errors.email"
                >
                    <input
                        id="c-email"
                        v-model="clientForm.email"
                        type="email"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-contact"
                    label="Contact person"
                >
                    <input
                        id="c-contact"
                        v-model="clientForm.contact_person"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-line1"
                    label="Address"
                    class="col-span-2"
                >
                    <input
                        id="c-line1"
                        v-model="clientForm.address.line1"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-city"
                    label="City"
                >
                    <input
                        id="c-city"
                        v-model="clientForm.address.city"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="c-postcode"
                    label="Postcode"
                >
                    <input
                        id="c-postcode"
                        v-model="clientForm.address.postcode"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="newClient = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="client-form"
                    :disabled="clientForm.processing"
                >
                    Add client
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
