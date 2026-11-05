<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Item { id: number; code: string; label_en: string; label_ms: string | null; effective_from: string; effective_to: string | null; notes: string | null; active: boolean }
interface Rate { id: number; rate: string; unit: string; version: number; effective_from: string; effective_to: string | null; notes: string | null }
defineProps<{
    dictionaries: { id: number; code: string; name: string; system: boolean; items: Item[] }[];
    claimTypes: { id: number; code: string; name: string; receipt_required: boolean; is_medical: boolean; per_claim_limit: string | null; notes: string | null; rates: Rate[] }[];
    can: { edit: boolean };
}>();
const item = useForm({ dictionary_id: 0, code: '', label_en: '', label_ms: '', effective_from: new Date().toISOString().slice(0, 10), notes: '' });
const rate = useForm({ claim_type_id: 0, rate: '', unit: 'km', effective_from: new Date().toISOString().slice(0, 10), notes: '' });
</script>

<template>
    <AppLayout>
        <Head title="Dictionaries" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Dictionaries & claim types
            </h1>
        </template>
        <details
            v-for="d in dictionaries"
            :key="d.id"
            class="mb-3 rounded-lg border border-surface-200 bg-white p-3"
        >
            <summary class="cursor-pointer font-semibold">
                {{ d.name }} <span class="font-mono text-xs text-surface-500">{{ d.code }}</span> · {{ d.items.length }} items
            </summary>
            <SimpleTable
                class="mt-2"
                :columns="[{ key: 'code', label: 'Code' }, { key: 'label_en', label: 'EN' }, { key: 'label_ms', label: 'BM' }, { key: 'effective_from', label: 'From' }, { key: 'effective_to', label: 'To' }, { key: 'active', label: 'Active' }, { key: 'notes', label: 'Notes' }]"
                :rows="d.items"
            >
                <template #cell-active="{ row }">
                    {{ row.active ? 'yes' : 'no' }}
                </template>
            </SimpleTable>
            <form
                v-if="can.edit"
                class="mt-2 grid grid-cols-2 gap-2 text-sm md:grid-cols-6"
                @submit.prevent="item.transform((d2) => ({ ...d2 })).post(route('admin.dictionaries.items.store', d.id), { onSuccess: () => item.reset() })"
            >
                <input
                    v-model="item.code"
                    required
                    placeholder="code"
                    aria-label="Code"
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <input
                    v-model="item.label_en"
                    required
                    placeholder="label EN"
                    aria-label="Label EN"
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <input
                    v-model="item.label_ms"
                    placeholder="label BM"
                    aria-label="Label BM"
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <input
                    v-model="item.effective_from"
                    type="date"
                    aria-label="Effective from"
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <input
                    v-model="item.notes"
                    placeholder="notes"
                    aria-label="Notes"
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <button
                    type="submit"
                    class="rounded-md bg-brand-600 px-3 py-1 text-white"
                >
                    Add
                </button>
            </form>
        </details>

        <h2 class="mb-2 mt-8 font-semibold">
            Claim types (rates are versioned)
        </h2>
        <div
            v-for="c in claimTypes"
            :key="c.id"
            class="mb-3 rounded-lg border border-surface-200 bg-white p-3 text-sm"
        >
            <div class="font-semibold">
                {{ c.name }} <span class="font-mono text-xs text-surface-500">{{ c.code }}</span>
                <span class="ml-2 text-xs">{{ c.receipt_required ? 'receipt required' : 'no receipt' }}{{ c.is_medical ? ' · medical (clinical detail restricted)' : '' }}{{ c.per_claim_limit ? ' · limit RM ' + c.per_claim_limit : '' }}</span>
            </div>
            <p class="text-xs text-surface-500">
                {{ c.notes }}
            </p>
            <ul class="mt-1 text-xs">
                <li
                    v-for="r in c.rates"
                    :key="r.id"
                >
                    v{{ r.version }}: RM {{ r.rate }} / {{ r.unit }} from {{ r.effective_from }}{{ r.effective_to ? ' to ' + r.effective_to : '' }}
                </li>
            </ul>
            <form
                v-if="can.edit"
                class="mt-2 flex flex-wrap gap-2"
                @submit.prevent="rate.post(route('admin.claim-types.rates.store', c.id), { onSuccess: () => rate.reset() })"
            >
                <input
                    v-model="rate.rate"
                    required
                    placeholder="rate (decimal)"
                    aria-label="Rate"
                    inputmode="decimal"
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <select
                    v-model="rate.unit"
                    aria-label="Unit"
                    class="rounded-md border border-surface-300 px-2 py-1"
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
                    class="rounded-md border border-surface-300 px-2 py-1"
                >
                <button
                    type="submit"
                    class="rounded-md bg-brand-600 px-3 py-1 text-white"
                >
                    New rate version
                </button>
            </form>
        </div>
    </AppLayout>
</template>
