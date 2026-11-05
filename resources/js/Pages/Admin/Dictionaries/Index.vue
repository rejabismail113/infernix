<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

interface Item { id: number; code: string; label_en: string; label_ms: string | null; effective_from: string; effective_to: string | null; notes: string | null; active: boolean }
interface Rate { id: number; rate: string; unit: string; version: number; effective_from: string; effective_to: string | null; notes: string | null }
const props = defineProps<{
    dictionaries: { id: number; code: string; name: string; system: boolean; items: Item[] }[];
    claimTypes: { id: number; code: string; name: string; receipt_required: boolean; is_medical: boolean; per_claim_limit: string | null; notes: string | null; rates: Rate[] }[];
    can: { edit: boolean };
}>();

const tabs = computed(() => [...props.dictionaries.map((d) => ({ key: String(d.id), label: d.name })), { key: 'claims', label: 'Claim types & rates' }]);
const active = ref(tabs.value[0]?.key ?? 'claims');
const dict = computed(() => props.dictionaries.find((d) => String(d.id) === active.value));
const item = useForm({ code: '', label_en: '', label_ms: '', effective_from: new Date().toISOString().slice(0, 10), notes: '' });
const rate = useForm({ rate: '' as string | number | null, unit: 'km', effective_from: new Date().toISOString().slice(0, 10), notes: '' });
const rateFor = ref<number | null>(null);
const itemColumns: TableColumn[] = [
    { key: 'code', label: 'Code', sortable: true }, { key: 'label_en', label: 'Label (EN)', sortable: true }, { key: 'label_ms', label: 'Label (BM)' },
    { key: 'effective_from', label: 'From', sortable: true }, { key: 'effective_to', label: 'To' }, { key: 'active', label: 'Status' }, { key: 'notes', label: 'Notes', hidden: true },
];
</script>

<template>
    <AppLayout
        title="Dictionaries"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Dictionaries' }]"
    >
        <div
            role="tablist"
            aria-label="Dictionaries"
            class="flex flex-wrap gap-1 rounded-lg bg-white p-1 ring-1 ring-surface-200"
        >
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="active === t.key"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="active === t.key ? 'bg-brand-50 text-brand-700' : 'text-surface-600 hover:bg-surface-100'"
                @click="active = t.key"
            >
                {{ t.label }}
            </button>
        </div>

        <template v-if="dict">
            <DataTable
                :columns="itemColumns"
                :rows="dict.items"
                :prefs-key="`admin.dictionary.${dict.code}`"
                :caption="dict.name"
            >
                <template #cell-code="{ row }">
                    <span class="font-mono text-xs">{{ row.code }}</span>
                </template>
                <template #cell-active="{ row }">
                    <StatusChip :status="row.active ? 'active' : 'inactive'" />
                </template>
            </DataTable>
            <Card
                v-if="can.edit"
                title="Add item"
                description="Items are versioned by effective date; existing items are never renumbered."
            >
                <form
                    class="grid grid-cols-1 gap-3 text-sm md:grid-cols-6"
                    @submit.prevent="item.post(route('admin.dictionaries.items.store', dict.id), { onSuccess: () => item.reset() })"
                >
                    <input
                        v-model="item.code"
                        required
                        placeholder="code"
                        aria-label="Code"
                        class="rounded-md border border-surface-300 px-3 py-2"
                    >
                    <input
                        v-model="item.label_en"
                        required
                        placeholder="Label (EN)"
                        aria-label="Label EN"
                        class="rounded-md border border-surface-300 px-3 py-2"
                    >
                    <input
                        v-model="item.label_ms"
                        placeholder="Label (BM)"
                        aria-label="Label BM"
                        class="rounded-md border border-surface-300 px-3 py-2"
                    >
                    <input
                        v-model="item.effective_from"
                        type="date"
                        aria-label="Effective from"
                        class="rounded-md border border-surface-300 px-3 py-2"
                    >
                    <input
                        v-model="item.notes"
                        placeholder="notes"
                        aria-label="Notes"
                        class="rounded-md border border-surface-300 px-3 py-2"
                    >
                    <Button
                        type="submit"
                        :disabled="item.processing"
                    >
                        Add
                    </Button>
                    <p
                        v-if="Object.keys(item.errors).length"
                        class="col-span-full text-red-600"
                        role="alert"
                    >
                        {{ Object.values(item.errors).join(' ') }}
                    </p>
                </form>
            </Card>
        </template>

        <template v-else>
            <Card
                v-for="c in claimTypes"
                :key="c.id"
                :title="c.name"
                :description="c.notes ?? undefined"
            >
                <template #actions>
                    <StatusChip
                        :status="c.receipt_required ? 'active' : 'draft'"
                        :label="c.receipt_required ? 'Receipt required' : 'No receipt'"
                    />
                    <StatusChip
                        v-if="c.is_medical"
                        status="medical"
                        label="Medical — clinical detail restricted"
                    />
                </template>
                <DataTable
                    :columns="[{ key: 'version', label: 'Version', numeric: true }, { key: 'rate', label: 'Rate (RM)', numeric: true }, { key: 'unit', label: 'Per' }, { key: 'effective_from', label: 'From' }, { key: 'effective_to', label: 'To' }]"
                    :rows="c.rates"
                    empty-text="No rate — amounts are entered per claim line."
                />
                <form
                    v-if="can.edit"
                    class="mt-3 flex flex-wrap items-center gap-2 text-sm"
                    @submit.prevent="rate.post(route('admin.claim-types.rates.store', c.id), { onSuccess: () => { rate.reset(); rateFor = null; } })"
                    @focusin="rateFor = c.id"
                >
                    <div class="w-44">
                        <MoneyInput
                            v-model="rate.rate"
                            :invalid="rateFor === c.id && Boolean(rate.errors.rate)"
                        />
                    </div>
                    <select
                        v-model="rate.unit"
                        aria-label="Unit"
                        class="rounded-md border border-surface-300 px-2 py-2"
                    >
                        <option
                            v-for="u in ['km', 'day', 'session', 'item']"
                            :key="u"
                        >
                            {{ u }}
                        </option>
                    </select>
                    <input
                        v-model="rate.effective_from"
                        type="date"
                        aria-label="Effective from"
                        class="rounded-md border border-surface-300 px-2 py-2"
                    >
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        New rate version
                    </Button>
                </form>
            </Card>
        </template>
    </AppLayout>
</template>
