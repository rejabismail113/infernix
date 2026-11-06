<script setup lang="ts">
/**
 * Subscriptions (M10-04/05). The 90/60/30-day alert opens a REVIEW task only (renew / cancel). Money appears only
 * when the supplier bill or payment is recorded in Finance and linked here (WF-06, UAT-12).
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import FinanceLinker from '@/Components/Finance/FinanceLinker.vue';
import LinkedRecords from '@/Components/Finance/LinkedRecords.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';
import type { FieldDef, FinanceRecordSnapshot, Option } from '@/types/operations';

interface Sub { id: number; vendor_id: number; vendor: string; product: string; plan: string | null; license_owner_staff_id: number | null; owner: string | null; seats: number; price: string; currency: string; billing_cycle: string; auto_renew: boolean; start_date: string | null; renewal_date: string; days: number; status: string; project_id: number | null; notes: string | null; annualised: string; paid: string; review: { id: number; alert_level: number; renewal_date: string } | null; history: { renewal_date: string; status: string; note: string | null }[]; finance: FinanceRecordSnapshot[]; can_edit: boolean }
const props = defineProps<{ subscriptions: Sub[]; vendors: Option[]; staff: Option[]; projects: Option[]; can: { create: boolean; link_finance: boolean } }>();
const reviews = computed(() => props.subscriptions.filter((s) => s.review !== null));
const fields = computed<FieldDef[]>(() => [
    { key: 'vendor_id', label: 'Vendor', type: 'select', options: props.vendors.map((v) => ({ value: v.id, label: v.label })), required: true }, { key: 'product', label: 'Product', type: 'text', required: true },
    { key: 'plan', label: 'Plan', type: 'text' }, { key: 'license_owner_staff_id', label: 'Licence owner', type: 'select', options: props.staff.map((s) => ({ value: s.id, label: s.label })) },
    { key: 'seats', label: 'Seats', type: 'number', required: true }, { key: 'price', label: 'Price per cycle', type: 'money', required: true },
    { key: 'currency', label: 'Currency', type: 'select', options: ['MYR', 'USD', 'SGD', 'EUR', 'GBP'].map((c) => ({ value: c, label: c })) },
    { key: 'billing_cycle', label: 'Billing cycle', type: 'select', options: ['monthly', 'quarterly', 'annual', 'one_off'].map((c) => ({ value: c, label: c.replace('_', ' ') })), required: true },
    { key: 'start_date', label: 'Started', type: 'date' }, { key: 'renewal_date', label: 'Next renewal', type: 'date', required: true }, { key: 'auto_renew', label: 'Auto-renews', type: 'checkbox' },
    { key: 'project_id', label: 'Project', type: 'select', options: props.projects.map((p) => ({ value: p.id, label: p.label })) }, { key: 'notes', label: 'Notes', type: 'textarea' },
]);
const open = ref(false); const editing = ref<Sub | null>(null);
const blank = { vendor_id: '' as number | '', product: '', plan: '', license_owner_staff_id: '' as number | '', seats: '1', price: '', currency: 'MYR', billing_cycle: 'annual', start_date: '', renewal_date: '', auto_renew: true, project_id: '' as number | '', notes: '' };
const form = useForm({ ...blank });
function edit(s: Sub | null) {
    editing.value = s;
    form.defaults(s ? { vendor_id: s.vendor_id, product: s.product, plan: s.plan ?? '', license_owner_staff_id: s.license_owner_staff_id ?? '', seats: String(s.seats), price: s.price, currency: s.currency, billing_cycle: s.billing_cycle,
        start_date: s.start_date ?? '', renewal_date: s.renewal_date, auto_renew: s.auto_renew, project_id: s.project_id ?? '', notes: s.notes ?? '' } : { ...blank });
    form.reset(); form.clearErrors(); open.value = true;
}
const save = () => {
    const t = form.transform((d) => blanksToNull(d, ['license_owner_staff_id', 'start_date', 'project_id']));
    const opts = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    if (editing.value) t.put(route('procurement.subscriptions.update', editing.value.id), opts); else t.post(route('procurement.subscriptions.store'), opts);
};
const decide = (s: Sub, decision: 'renew' | 'cancel') => {
    const note = window.prompt(decision === 'renew' ? 'Note (optional) — the supplier bill is recorded in Finance when it arrives' : 'Why cancel?');
    if (decision === 'cancel' && !note) return;
    router.post(route('procurement.subscription-reviews.decide', s.review?.id ?? 0), { decision, note }, { preserveScroll: true });
};
const expanded = ref<number | null>(null);
</script>

<template>
    <AppLayout
        title="Subscriptions"
        :breadcrumbs="[{ label: 'Procurement' }, { label: 'Subscriptions' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'procurement.renewal-calendar')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Renewal calendar
            </Link>
            <Button
                v-if="can.create"
                data-test="add-subscription"
                @click="edit(null)"
            >
                Add subscription
            </Button>
        </template>

        <Card
            v-if="reviews.length"
            title="Renewal reviews"
            description="Raised by the 90/60/30-day alert. Deciding creates no transaction."
            class="mb-4"
        >
            <ul class="divide-y divide-surface-100 text-sm">
                <li
                    v-for="s in reviews"
                    :key="s.id"
                    class="flex flex-wrap items-center gap-2 py-2"
                    :data-test="`review-${s.id}`"
                >
                    <span class="font-medium">{{ s.product }}</span>
                    <span class="text-surface-500">{{ s.vendor }} · renews {{ s.renewal_date }} ({{ s.days }} days) · <Amount :value="s.price" /> / {{ s.billing_cycle }}</span>
                    <StatusChip
                        status="pending"
                        :label="`${s.review?.alert_level}-day alert`"
                    />
                    <span
                        v-if="s.can_edit"
                        class="ml-auto flex gap-2"
                    >
                        <Button
                            :data-test="`renew-${s.id}`"
                            @click="decide(s, 'renew')"
                        >Renew</Button>
                        <Button
                            variant="danger"
                            @click="decide(s, 'cancel')"
                        >Cancel</Button>
                    </span>
                </li>
            </ul>
        </Card>

        <div class="overflow-x-auto rounded-lg border border-surface-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-surface-50 text-left text-surface-500">
                    <tr>
                        <th class="px-3 py-2">
                            Product
                        </th><th>Vendor</th><th>Owner</th><th>Cycle</th><th class="text-right">
                            Price
                        </th><th class="text-right">
                            Annualised
                        </th><th>Renews</th><th>Auto</th><th>Status</th><th class="text-right">
                            Paid (linked)
                        </th><th />
                    </tr>
                </thead>
                <tbody>
                    <template
                        v-for="s in subscriptions"
                        :key="s.id"
                    >
                        <tr class="border-t border-surface-100">
                            <td class="px-3 py-2 font-medium">
                                {{ s.product }} <span class="text-xs text-surface-500">{{ s.plan }}</span>
                            </td>
                            <td>{{ s.vendor }}</td>
                            <td>{{ s.owner ?? '—' }}</td>
                            <td>{{ s.billing_cycle }}</td>
                            <td class="text-right font-mono">
                                <Amount :value="s.price" />
                            </td>
                            <td class="text-right font-mono">
                                <Amount :value="s.annualised" />
                            </td>
                            <td>{{ s.renewal_date }} <span class="text-xs text-surface-500">({{ s.days }}d)</span></td>
                            <td>{{ s.auto_renew ? 'yes' : 'no' }}</td>
                            <td><StatusChip :status="s.status" /></td>
                            <td class="text-right font-mono">
                                <Amount :value="s.paid" />
                            </td>
                            <td class="whitespace-nowrap px-2 text-right">
                                <button
                                    type="button"
                                    class="text-xs text-brand-700 hover:underline"
                                    @click="expanded = expanded === s.id ? null : s.id"
                                >
                                    {{ expanded === s.id ? 'Hide' : 'Details' }}
                                </button>
                                <button
                                    v-if="s.can_edit && s.status !== 'cancelled'"
                                    type="button"
                                    class="ml-2 text-xs text-brand-700 hover:underline"
                                    @click="edit(s)"
                                >
                                    Edit
                                </button>
                            </td>
                        </tr>
                        <tr
                            v-if="expanded === s.id"
                            class="bg-surface-50"
                        >
                            <td
                                colspan="11"
                                class="space-y-2 px-3 py-3"
                            >
                                <p
                                    v-if="s.notes"
                                    class="text-sm"
                                >
                                    {{ s.notes }}
                                </p>
                                <p
                                    v-if="s.history.length"
                                    class="text-xs text-surface-500"
                                >
                                    Past reviews: <span
                                        v-for="h in s.history"
                                        :key="h.renewal_date"
                                    >{{ h.renewal_date }} {{ h.status }}; </span>
                                </p>
                                <LinkedRecords
                                    v-if="can.link_finance"
                                    :records="s.finance"
                                    empty="No supplier bill or payment linked yet."
                                />
                                <FinanceLinker
                                    v-if="can.link_finance && s.can_edit"
                                    :link-url="route('procurement.subscriptions.link', s.id)"
                                    :types="['bill', 'payment', 'transaction']"
                                    label="Link the supplier bill / payment"
                                />
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!subscriptions.length">
                        <td
                            colspan="11"
                            class="px-3 py-4 text-center text-surface-500"
                        >
                            No subscriptions recorded.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :open="open"
            :title="editing ? `Edit ${editing.product}` : 'Add subscription'"
            @close="open = false"
        >
            <form
                id="sub-form"
                @submit.prevent="save"
            >
                <SchemaFields
                    :fields="fields"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="sub"
                    @set="(k, v) => setField(form, k, v)"
                />
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="mt-2 text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="sub-form"
                    :disabled="form.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
