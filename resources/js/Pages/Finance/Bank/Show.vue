<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import FileDropzone from '@/Components/Form/FileDropzone.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import { compareDecimals, sumDecimals } from '@/lib/decimal';
import type { TableColumn } from '@/types/table';

interface Txn { id: number; txn_date: string; description: string; reference: string | null; amount: string; remaining: string; status: string; matched_to: { journal_no: string; journal_id: number; split: boolean; type: string } | null; suggestions: { id: number; journal_no: string; date: string; description: string | null }[] }
const props = defineProps<{
    bank: { id: number; label: string; gl: string; opening_balance: string; ledger_balance: string };
    transactions: LaravelPage<Txn>; filters: Record<string, string | null>; profiles: { id: number; name: string; notes: string | null }[];
    periods: { id: number; label: string }[]; can: { edit: boolean }; rules: number;
}>();
const page = usePage<{ flash: { import?: { imported: number; skipped: number } } }>();
const imported = computed(() => (page.props.flash as { import?: { imported: number; skipped: number } }).import);
const table = useServerTable(() => route('finance.bank.show', props.bank.id), () => props.filters);
const imp = useForm<{ file: File | null; profile_id: number | '' }>({ file: null, profile_id: props.profiles[0]?.id ?? '' });
const manual = useForm({ txn_date: new Date().toISOString().slice(0, 10), description: '', reference: '', amount: '' as string | number | null });
const period = ref(props.periods[0]?.id ?? 0);
const columns: TableColumn[] = [
    { key: 'pick', label: '', hideable: false }, { key: 'txn_date', label: 'Date', hideable: false }, { key: 'description', label: 'Description' }, { key: 'reference', label: 'Reference' },
    { key: 'amount', label: 'Amount', numeric: true }, { key: 'status', label: 'Status' }, { key: 'match', label: 'Ledger match' },
];
// Split match (M01-07): tick bank lines, pick ledger lines with an equal total.
const selected = ref<number[]>([]);
const splitOpen = ref(false);
const ledgerQ = ref('');
const ledgerLines = ref<{ id: number; journal_no: string; date: string; description: string | null; remaining: string }[]>([]);
const pickedLines = ref<number[]>([]);
const bankTotal = computed(() => sumDecimals(props.transactions.data.filter((t) => selected.value.includes(t.id)).map((t) => t.remaining)));
const ledgerTotal = computed(() => sumDecimals(ledgerLines.value.filter((l) => pickedLines.value.includes(l.id)).map((l) => l.remaining)));
async function loadLedger() {
    const res = await fetch(route('finance.bank.ledger-lines', { bank: props.bank.id, q: ledgerQ.value }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (res.ok) ledgerLines.value = ((await res.json()) as { lines: typeof ledgerLines.value }).lines;
}
function openSplit() { splitOpen.value = true; pickedLines.value = []; void loadLedger(); }
const splitMatch = () => router.post(route('finance.bank.match-group', props.bank.id), { transaction_ids: selected.value, journal_line_ids: pickedLines.value }, { preserveScroll: true, onSuccess: () => { splitOpen.value = false; selected.value = []; } });
const autoMatch = () => router.post(route('finance.bank.auto-match', props.bank.id), {}, { preserveScroll: true });
const match = (txn: Txn, lineId: number) => router.post(route('finance.bank.match', txn.id), { journal_line_id: lineId }, { preserveScroll: true });
const unmatch = (txn: Txn) => {
    const reason = window.prompt('Why remove this match?');
    if (reason) router.post(route('finance.bank.unmatch', txn.id), { reason }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout
        :title="bank.label"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bank', href: route('finance.bank.index') }, { label: bank.label }]"
    >
        <template #actions>
            <select
                v-model="period"
                aria-label="Period"
                class="rounded-md border border-surface-300 px-2 py-2 text-sm"
            >
                <option
                    v-for="p in periods"
                    :key="p.id"
                    :value="p.id"
                >
                    {{ p.label }}
                </option>
            </select>
            <Link
                :href="route('finance.bank.reconciliation', { bank: bank.id, period })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Reconciliation statement
            </Link>
            <Link
                :href="route('finance.bank.settings')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Templates & rules
            </Link>
            <Button
                v-if="can.edit"
                variant="secondary"
                data-test="auto-match"
                @click="autoMatch"
            >
                Auto-match ({{ rules }} rules)
            </Button>
            <Button
                v-if="can.edit"
                variant="secondary"
                :disabled="!selected.length"
                data-test="split-match"
                @click="openSplit"
            >
                Split match ({{ selected.length }})
            </Button>
        </template>
        <div class="grid gap-5 xl:grid-cols-3">
            <Card title="Balances">
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-surface-500">
                        GL account
                    </dt><dd>{{ bank.gl }}</dd>
                    <dt class="text-surface-500">
                        Statement opening
                    </dt><dd><Amount :value="bank.opening_balance" /></dd>
                    <dt class="text-surface-500">
                        Ledger today
                    </dt><dd><Amount :value="bank.ledger_balance" /></dd>
                </dl>
            </Card>
            <Card
                v-if="can.edit"
                title="Import statement (CSV)"
                description="Re-importing the same file never duplicates lines."
            >
                <form
                    class="space-y-2"
                    @submit.prevent="imp.post(route('finance.bank.import', bank.id), { forceFormData: true, preserveScroll: true, onSuccess: () => imp.reset('file') })"
                >
                    <select
                        v-model="imp.profile_id"
                        aria-label="Format"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                        <option
                            v-for="p in profiles"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }}
                        </option>
                    </select>
                    <FileDropzone
                        id="statement"
                        v-model="imp.file"
                        :accept="['csv']"
                        label="Drop the bank CSV here"
                        :error="imp.errors.file"
                    />
                    <Button
                        type="submit"
                        :disabled="!imp.file || imp.processing"
                    >
                        Import
                    </Button>
                    <p
                        v-if="imported"
                        class="text-sm text-emerald-700"
                    >
                        {{ imported.imported }} new lines, {{ imported.skipped }} duplicates skipped.
                    </p>
                </form>
            </Card>
            <Card
                v-if="can.edit"
                title="Add a line manually"
            >
                <form
                    class="space-y-2"
                    @submit.prevent="manual.post(route('finance.bank.transactions.store', bank.id), { preserveScroll: true, onSuccess: () => manual.reset() })"
                >
                    <input
                        v-model="manual.txn_date"
                        type="date"
                        aria-label="Date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <input
                        v-model="manual.description"
                        required
                        placeholder="Description"
                        aria-label="Description"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <input
                        v-model="manual.reference"
                        placeholder="Reference"
                        aria-label="Reference"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                    <MoneyInput
                        v-model="manual.amount"
                        allow-negative
                        placeholder="-100.00 for money out"
                    />
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Add line
                    </Button>
                </form>
            </Card>
        </div>
        <DataTable
            :columns="columns"
            :rows="transactions.data"
            :pagination="toPagination(transactions)"
            :loading="table.loading.value"
            prefs-key="finance.bank.lines"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #toolbar>
                <select
                    :value="filters.status ?? ''"
                    aria-label="Status filter"
                    class="rounded border border-surface-200 px-2 py-1 text-sm"
                    @change="table.visit({ status: ($event.target as HTMLSelectElement).value, page: 1 })"
                >
                    <option value="">
                        All lines
                    </option><option value="unmatched">
                        Unmatched
                    </option><option value="matched">
                        Matched
                    </option>
                </select>
            </template>
            <template #cell-pick="{ row }">
                <input
                    v-if="can.edit && row.status !== 'matched'"
                    v-model="selected"
                    type="checkbox"
                    :value="row.id"
                    :aria-label="`Select bank line ${row.txn_date} ${row.description}`"
                >
            </template>
            <template #cell-amount="{ row }">
                <Amount :value="row.amount" />
                <span
                    v-if="row.status === 'partial'"
                    class="block text-xs text-amber-700"
                >open <Amount :value="row.remaining" /></span>
            </template>
            <template #cell-status="{ row }">
                <StatusChip
                    :status="row.status === 'matched' ? 'approved' : 'pending'"
                    :label="row.matched_to?.type === 'auto' ? `${row.status} (auto)` : row.status"
                />
            </template>
            <template #cell-match="{ row }">
                <span v-if="row.matched_to">
                    <Link
                        :href="route('finance.journals.show', row.matched_to.journal_id)"
                        class="font-mono text-brand-700 hover:underline"
                    >{{ row.matched_to.journal_no }}</Link>
                    <span
                        v-if="row.matched_to.split"
                        class="ml-1 rounded bg-surface-100 px-1 text-xs"
                    >split</span>
                    <button
                        v-if="can.edit"
                        type="button"
                        class="ml-2 text-xs text-surface-500 hover:underline"
                        @click="unmatch(row)"
                    >unmatch</button>
                </span>
                <span
                    v-else-if="row.suggestions.length"
                    class="flex flex-col gap-1"
                >
                    <button
                        v-for="s in row.suggestions"
                        :key="s.id"
                        type="button"
                        class="rounded border border-brand-200 bg-brand-50 px-2 py-0.5 text-left text-xs text-brand-800 hover:bg-brand-100 disabled:opacity-50"
                        :disabled="!can.edit"
                        @click="match(row, s.id)"
                    >Match {{ s.journal_no }} · {{ s.date }} · {{ s.description }}</button>
                </span>
                <span
                    v-else
                    class="text-xs text-surface-400"
                >no candidate (record it, then match)</span>
            </template>
        </DataTable>
        <Modal
            :open="splitOpen"
            title="Split match"
            @close="splitOpen = false"
        >
            <p class="text-sm">
                Selected bank lines: <strong><Amount :value="bankTotal" /></strong> · ledger lines picked: <strong><Amount :value="ledgerTotal" /></strong>
                <span
                    v-if="compareDecimals(bankTotal, ledgerTotal) === 0 && pickedLines.length"
                    class="ml-2 text-emerald-700"
                >totals agree</span>
            </p>
            <form
                class="my-2 flex gap-2"
                @submit.prevent="loadLedger"
            >
                <input
                    v-model="ledgerQ"
                    type="search"
                    placeholder="Journal no. or description"
                    aria-label="Search ledger lines"
                    class="flex-1 rounded-md border border-surface-300 px-3 py-2 text-sm"
                >
                <Button
                    type="submit"
                    variant="secondary"
                >
                    Search
                </Button>
            </form>
            <ul class="max-h-80 divide-y divide-surface-100 overflow-auto text-sm">
                <li
                    v-for="l in ledgerLines"
                    :key="l.id"
                    class="flex items-center gap-2 py-1"
                >
                    <input
                        v-model="pickedLines"
                        type="checkbox"
                        :value="l.id"
                        :aria-label="`Ledger line ${l.journal_no}`"
                    >
                    <span class="font-mono">{{ l.journal_no }}</span>
                    <span class="text-surface-500">{{ l.date }} · {{ l.description }}</span>
                    <span class="ml-auto font-mono"><Amount :value="l.remaining" /></span>
                </li>
                <li
                    v-if="!ledgerLines.length"
                    class="py-2 text-surface-500"
                >
                    No open ledger lines.
                </li>
            </ul>
            <template #footer>
                <Button
                    :disabled="!pickedLines.length || compareDecimals(bankTotal, ledgerTotal) !== 0"
                    data-test="confirm-split"
                    @click="splitMatch"
                >
                    Match
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
