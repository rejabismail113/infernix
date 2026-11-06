<script setup lang="ts">
/** Bank statement import templates (column mapping per bank) and auto-match rules (M01-07). */
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';

interface Profile { id: number; name: string; template_key: string | null; bank_name: string | null; column_mapping: Record<string, number>; date_format: string; has_header: boolean; delimiter: string; skip_rows: number; notes: string | null; active: boolean }
interface Rule { id: number; name: string; bank_account_id: number | null; priority: number; direction: string; date_window_days: number; reference_pattern: string | null; payee_alias: string | null; ledger_keyword: string | null; active: boolean; notes: string | null }
const props = defineProps<{ profiles: Profile[]; rules: Rule[]; accounts: { id: number; label: string }[]; can: { edit: boolean } }>();
const COLS = ['date', 'description', 'reference', 'amount', 'debit', 'credit', 'balance'] as const;
const FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'm/d/Y', 'd M Y', 'd.m.Y'];

const pOpen = ref(false); const pEditing = ref<Profile | null>(null);
const pf = useForm({ name: '', bank_name: '', date_format: 'd/m/Y', has_header: true, delimiter: ',', skip_rows: '0', notes: '', active: true, column_mapping: Object.fromEntries(COLS.map((c) => [c, ''])) as Record<string, string> });
function editProfile(p: Profile | null) {
    pEditing.value = p;
    pf.defaults(p ? { name: p.name, bank_name: p.bank_name ?? '', date_format: p.date_format, has_header: p.has_header, delimiter: p.delimiter === '\t' ? 'tab' : p.delimiter, skip_rows: String(p.skip_rows), notes: p.notes ?? '', active: p.active,
        column_mapping: Object.fromEntries(COLS.map((c) => [c, p.column_mapping[c] === undefined ? '' : String(p.column_mapping[c])])) }
        : { name: '', bank_name: '', date_format: 'd/m/Y', has_header: true, delimiter: ',', skip_rows: '0', notes: '', active: true, column_mapping: Object.fromEntries(COLS.map((c) => [c, ''])) });
    pf.reset(); pf.clearErrors(); pOpen.value = true;
}
const saveProfile = () => {
    const t = pf.transform((d) => ({ ...d, column_mapping: Object.fromEntries(Object.entries(d.column_mapping).filter(([, v]) => v !== '').map(([k, v]) => [k, Number.parseInt(v, 10)])) }));
    const opts = { preserveScroll: true, onSuccess: () => { pOpen.value = false; } };
    if (pEditing.value) t.put(route('finance.bank.profiles.update', pEditing.value.id), opts); else t.post(route('finance.bank.profiles.store'), opts);
};

const rOpen = ref(false); const rEditing = ref<Rule | null>(null);
const rf = useForm({ name: '', bank_account_id: '' as number | '', priority: '50', direction: 'any', date_window_days: '3', reference_pattern: '', payee_alias: '', ledger_keyword: '', active: true, notes: '' });
function editRule(r: Rule | null) {
    rEditing.value = r;
    rf.defaults(r ? { name: r.name, bank_account_id: r.bank_account_id ?? '', priority: String(r.priority), direction: r.direction, date_window_days: String(r.date_window_days), reference_pattern: r.reference_pattern ?? '', payee_alias: r.payee_alias ?? '', ledger_keyword: r.ledger_keyword ?? '', active: r.active, notes: r.notes ?? '' }
        : { name: '', bank_account_id: '', priority: '50', direction: 'any', date_window_days: '3', reference_pattern: '', payee_alias: '', ledger_keyword: '', active: true, notes: '' });
    rf.reset(); rf.clearErrors(); rOpen.value = true;
}
const saveRule = () => {
    const t = rf.transform((d) => blanksToNull(d, ['bank_account_id', 'reference_pattern', 'payee_alias', 'ledger_keyword']));
    const opts = { preserveScroll: true, onSuccess: () => { rOpen.value = false; } };
    if (rEditing.value) t.put(route('finance.bank.rules.update', rEditing.value.id), opts); else t.post(route('finance.bank.rules.store'), opts);
};
</script>

<template>
    <AppLayout
        title="Bank templates & match rules"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bank', href: route('finance.bank.index') }, { label: 'Templates & rules' }]"
    >
        <div class="grid gap-5 xl:grid-cols-2">
            <Card
                title="Statement import templates"
                description="Column numbers start at 0. Map either a signed amount or debit + credit."
            >
                <template #actions>
                    <Button
                        v-if="can.edit"
                        variant="secondary"
                        @click="editProfile(null)"
                    >
                        New template
                    </Button>
                </template>
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="p in profiles"
                        :key="p.id"
                        class="py-2"
                    >
                        <button
                            type="button"
                            class="font-medium text-brand-700 hover:underline"
                            :disabled="!can.edit"
                            @click="editProfile(p)"
                        >
                            {{ p.name }}
                        </button>
                        <StatusChip
                            v-if="!p.active"
                            status="inactive"
                        />
                        <p class="text-xs text-surface-500">
                            {{ p.bank_name ?? 'Any bank' }} · {{ p.date_format }} · {{ Object.entries(props.profiles.find((x) => x.id === p.id)?.column_mapping ?? {}).map(([k, v]) => `${k}=${v}`).join(', ') }}
                        </p>
                        <p
                            v-if="p.notes"
                            class="text-xs text-surface-500"
                        >
                            {{ p.notes }}
                        </p>
                    </li>
                </ul>
            </Card>
            <Card
                title="Auto-match rules"
                description="Tried in priority order; a bank line is matched only when exactly one open ledger line with the same amount qualifies. Then the default rule (exact amount, ±3 days) applies."
            >
                <template #actions>
                    <Button
                        v-if="can.edit"
                        variant="secondary"
                        @click="editRule(null)"
                    >
                        New rule
                    </Button>
                </template>
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="r in rules"
                        :key="r.id"
                        class="py-2"
                    >
                        <span class="mr-2 rounded bg-surface-100 px-1 text-xs">#{{ r.priority }}</span>
                        <button
                            type="button"
                            class="font-medium text-brand-700 hover:underline"
                            :disabled="!can.edit"
                            @click="editRule(r)"
                        >
                            {{ r.name }}
                        </button>
                        <StatusChip
                            v-if="!r.active"
                            status="inactive"
                        />
                        <p class="text-xs text-surface-500">
                            {{ r.direction }} · ±{{ r.date_window_days }} days {{ r.reference_pattern ? `· ref ${r.reference_pattern}` : '' }} {{ r.payee_alias ? `· payee "${r.payee_alias}"` : '' }} {{ r.ledger_keyword ? `· ledger "${r.ledger_keyword}"` : '' }}
                        </p>
                    </li>
                    <li
                        v-if="!rules.length"
                        class="py-2 text-surface-500"
                    >
                        No rules — only the default rule applies.
                    </li>
                </ul>
            </Card>
        </div>

        <Modal
            :open="pOpen"
            :title="pEditing ? `Edit ${pEditing.name}` : 'New import template'"
            @close="pOpen = false"
        >
            <form
                id="profile-form"
                class="space-y-3"
                @submit.prevent="saveProfile"
            >
                <SchemaFields
                    :fields="[{ key: 'name', label: 'Name', type: 'text', required: true, span: 2 }, { key: 'bank_name', label: 'Bank', type: 'text' },
                              { key: 'date_format', label: 'Date format', type: 'select', options: FORMATS.map((f) => ({ value: f, label: f })) },
                              { key: 'delimiter', label: 'Delimiter', type: 'select', options: [{ value: ',', label: 'comma' }, { value: ';', label: 'semicolon' }, { value: '|', label: 'pipe' }, { value: 'tab', label: 'tab' }] },
                              { key: 'skip_rows', label: 'Rows to skip before the header', type: 'number' }, { key: 'has_header', label: 'Has a header row', type: 'checkbox' }, { key: 'active', label: 'Active', type: 'checkbox' },
                              { key: 'notes', label: 'Notes', type: 'textarea' }]"
                    :values="pf"
                    :errors="pf.errors"
                    id-prefix="pf"
                    @set="(k, v) => setField(pf, k, v)"
                />
                <fieldset class="grid grid-cols-4 gap-2 text-sm">
                    <legend class="mb-1 font-medium">
                        Column numbers
                    </legend>
                    <label
                        v-for="c in COLS"
                        :key="c"
                        class="flex flex-col"
                    >
                        {{ c }}
                        <input
                            v-model="pf.column_mapping[c]"
                            inputmode="numeric"
                            class="rounded border border-surface-300 px-2 py-1"
                        >
                    </label>
                </fieldset>
                <p
                    v-if="fieldError(pf.errors, 'column_mapping')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(pf.errors, 'column_mapping') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="profile-form"
                    :disabled="pf.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>

        <Modal
            :open="rOpen"
            :title="rEditing ? `Edit ${rEditing.name}` : 'New match rule'"
            @close="rOpen = false"
        >
            <form
                id="rule-form"
                @submit.prevent="saveRule"
            >
                <SchemaFields
                    :fields="[{ key: 'name', label: 'Name', type: 'text', required: true, span: 2 }, { key: 'bank_account_id', label: 'Bank account (blank = all)', type: 'select', options: accounts.map((a) => ({ value: a.id, label: a.label })) },
                              { key: 'priority', label: 'Priority (lower first)', type: 'number' }, { key: 'direction', label: 'Direction', type: 'select', options: [{ value: 'any', label: 'any' }, { value: 'in', label: 'money in' }, { value: 'out', label: 'money out' }] },
                              { key: 'date_window_days', label: 'Date window (± days)', type: 'number' },
                              { key: 'reference_pattern', label: 'Reference regex (group 1 must appear in the ledger)', type: 'text', span: 2, placeholder: '/\\b(INV-\\d{4}-\\d{4})\\b/i' },
                              { key: 'payee_alias', label: 'Payee alias (in bank description)', type: 'text' }, { key: 'ledger_keyword', label: 'Ledger keyword', type: 'text' },
                              { key: 'active', label: 'Active', type: 'checkbox' }, { key: 'notes', label: 'Notes', type: 'textarea' }]"
                    :values="rf"
                    :errors="rf.errors"
                    id-prefix="rf"
                    @set="(k, v) => setField(rf, k, v)"
                />
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="rule-form"
                    :disabled="rf.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
