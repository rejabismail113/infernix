<script setup lang="ts">
/**
 * Asset register (M05) + pending registrations: posted capital purchases waiting to be registered.
 * Registering from a purchase references it and creates NO expense (UAT-03).
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { subtractDecimals } from '@/lib/decimal';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';
import type { FieldDef, FinanceRecordSnapshot } from '@/types/operations';
import type { TableColumn } from '@/types/table';

interface AssetRow { id: number; asset_no: string; name: string; category: string | null; asset_class: string; serial_no: string | null; status: string; location: string | null; acquired_on: string; cost: string; warranty_expires_on: string | null; custodian: string | null; source: string }
interface Candidate { id: number; source_type: string; source: FinanceRecordSnapshot | null; description: string; amount: string; registered: string; purchase_date: string }
const props = defineProps<{
    assets: AssetRow[]; candidates: Candidate[]; categories: { id: number; name: string; default_class: string; useful_life_months: number; residual_percent: string }[];
    policy: { capital_threshold: string; low_value_threshold: string; posting: boolean }; filters: Record<string, string | null>; can: { create: boolean };
}>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', class: props.filters.class ?? '' });
const columns: TableColumn[] = [
    { key: 'asset_no', label: 'Asset no', sortable: true }, { key: 'name', label: 'Name', sortable: true }, { key: 'category', label: 'Category', sortable: true },
    { key: 'status', label: 'Status', sortable: true }, { key: 'custodian', label: 'Custodian' }, { key: 'location', label: 'Location' },
    { key: 'acquired_on', label: 'Acquired', sortable: true }, { key: 'cost', label: 'Cost', numeric: true, sortable: true }, { key: 'source', label: 'Source' },
];
const catOptions = computed(() => props.categories.map((c) => ({ value: c.id, label: c.name })));
const fields = computed<FieldDef[]>(() => [
    { key: 'name', label: 'Asset name', type: 'text', required: true, span: 2 }, { key: 'category_id', label: 'Category', type: 'select', options: catOptions.value },
    { key: 'serial_no', label: 'Serial no', type: 'text' }, { key: 'model', label: 'Model', type: 'text' }, { key: 'location', label: 'Location', type: 'text' },
    { key: 'warranty_expires_on', label: 'Warranty ends', type: 'date' }, { key: 'useful_life_months', label: 'Useful life (months)', type: 'number', help: 'Blank = category default' },
]);
const registering = ref<Candidate | null>(null);
const reg = useForm({ name: '', category_id: '' as number | '', serial_no: '', model: '', location: '', warranty_expires_on: '', useful_life_months: '', cost: '' });
function openRegister(c: Candidate) { registering.value = c; reg.reset(); reg.clearErrors(); reg.name = c.description; }
const register = () => reg.transform((d) => blanksToNull(d, ['category_id', 'warranty_expires_on', 'useful_life_months', 'cost'])).post(route('assets.candidates.register', registering.value?.id ?? 0), { onSuccess: () => { registering.value = null; } });
const dismiss = (c: Candidate) => { const reason = window.prompt('Why is this purchase not an asset?'); if (reason) router.post(route('assets.candidates.dismiss', c.id), { reason }, { preserveScroll: true }); };
const manualOpen = ref(false);
const manual = useForm({ name: '', category_id: '' as number | '', serial_no: '', model: '', location: '', warranty_expires_on: '', useful_life_months: '', acquisition_type: 'opening_balance', acquired_on: '', cost: '' });
const saveManual = () => manual.transform((d) => blanksToNull(d, ['category_id', 'warranty_expires_on', 'useful_life_months'])).post(route('assets.assets.store'));
const remaining = (c: Candidate) => subtractDecimals(c.amount, c.registered);
</script>

<template>
    <AppLayout
        title="Asset register"
        :breadcrumbs="[{ label: 'Operations' }, { label: 'Assets' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'assets.register')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Register report
            </Link>
            <Button
                v-if="can.create"
                variant="secondary"
                data-test="add-asset-manual"
                @click="manualOpen = true"
            >
                Opening balance / donation
            </Button>
        </template>

        <Card
            v-if="can.create && candidates.length"
            title="Pending registrations"
            description="Posted capital purchases. Register them here — the purchase is already in the ledger, so no expense is created."
            class="mb-4"
        >
            <ul class="divide-y divide-surface-100">
                <li
                    v-for="c in candidates"
                    :key="c.id"
                    class="flex flex-wrap items-center gap-3 py-2 text-sm"
                    :data-test="`candidate-${c.id}`"
                >
                    <a
                        v-if="c.source"
                        :href="c.source.url"
                        class="font-medium text-brand-700 hover:underline"
                    >{{ c.source.number }}</a>
                    <span>{{ c.description }}</span>
                    <span class="text-surface-500">{{ c.purchase_date }}</span>
                    <span class="ml-auto font-mono"><Amount :value="c.amount" /></span>
                    <Button
                        :data-test="`register-${c.id}`"
                        @click="openRegister(c)"
                    >
                        Register asset
                    </Button>
                    <Button
                        variant="secondary"
                        @click="dismiss(c)"
                    >
                        Not an asset
                    </Button>
                </li>
            </ul>
        </Card>

        <form
            class="mb-3 flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('assets.assets.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Name, asset or serial no."
                aria-label="Search assets"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.status"
                aria-label="Status"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any status
                </option>
                <option
                    v-for="s in ['in_store', 'assigned', 'in_repair', 'disposed', 'lost']"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace('_', ' ') }}
                </option>
            </select>
            <select
                v-model="f.class"
                aria-label="Class"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any class
                </option>
                <option value="capital_asset">
                    Capital asset
                </option>
                <option value="low_value_equipment">
                    Low-value equipment
                </option>
            </select>
            <Button
                type="submit"
                variant="secondary"
            >
                Filter
            </Button>
            <span class="ml-auto self-center text-xs text-surface-500">Policy (PLACEHOLDER): capital ≥ RM {{ policy.capital_threshold }}, low-value ≥ RM {{ policy.low_value_threshold }}, below = consumable · depreciation {{ policy.posting ? 'posted' : 'scheduled only' }}</span>
        </form>

        <DataTable
            :columns="columns"
            :rows="assets"
            prefs-key="assets"
            empty-text="No assets yet."
        >
            <template #cell-asset_no="{ row }">
                <Link
                    :href="route('assets.assets.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.asset_no }}
                </Link>
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
            <template #cell-cost="{ row }">
                <Amount :value="row.cost" />
            </template>
        </DataTable>

        <Modal
            :open="registering !== null"
            :title="`Register asset from ${registering?.source?.number ?? 'purchase'}`"
            @close="registering = null"
        >
            <form
                id="register-form"
                class="space-y-3"
                @submit.prevent="register"
            >
                <p class="rounded-md bg-emerald-50 p-2 text-sm text-emerald-900">
                    Unregistered amount RM {{ registering ? remaining(registering) : '' }}. Leave cost blank to register it all as one asset, or enter part of it to split (e.g. several laptops).
                </p>
                <SchemaFields
                    :fields="[...fields, { key: 'cost', label: 'Cost (RM)', type: 'money' }]"
                    :values="reg"
                    :errors="reg.errors"
                    id-prefix="reg"
                    @set="(k, v) => setField(reg, k, v)"
                />
                <p
                    v-if="fieldError(reg.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(reg.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="register-form"
                    :disabled="reg.processing"
                    data-test="confirm-register"
                >
                    Register (no new expense)
                </Button>
            </template>
        </Modal>

        <Modal
            :open="manualOpen"
            title="Register asset without a purchase"
            @close="manualOpen = false"
        >
            <form
                id="manual-form"
                class="space-y-3"
                @submit.prevent="saveManual"
            >
                <p class="text-sm text-surface-600">
                    For assets brought forward at go-live or donated. Purchased assets must be registered from their posted purchase.
                </p>
                <SchemaFields
                    :fields="[...fields, { key: 'acquisition_type', label: 'Acquired by', type: 'select', options: [{ value: 'opening_balance', label: 'Opening balance' }, { value: 'donation', label: 'Donation' }], required: true },
                              { key: 'acquired_on', label: 'Acquired on', type: 'date', required: true }, { key: 'cost', label: 'Cost / value (RM)', type: 'money', required: true }]"
                    :values="manual"
                    :errors="manual.errors"
                    id-prefix="man"
                    @set="(k, v) => setField(manual, k, v)"
                />
                <p
                    v-if="fieldError(manual.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(manual.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="manual-form"
                    :disabled="manual.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
