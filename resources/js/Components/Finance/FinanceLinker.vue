<script setup lang="ts">
/**
 * Search BOOKED finance records (posted expenses, approved bills/claims, paid payments) and link one to the
 * current record. Linking never creates money (rule 12); the server re-checks every permission.
 */
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Amount from '@/Components/Finance/Amount.vue';
import Button from '@/Components/Button.vue';
import type { FinanceRecordSnapshot } from '@/types/operations';

const props = withDefaults(defineProps<{ linkUrl: string; types: FinanceRecordSnapshot['type'][]; label?: string; extra?: Record<string, string> }>(), { label: 'Link finance record', extra: () => ({}) });
const type = ref<FinanceRecordSnapshot['type']>(props.types[0] ?? 'transaction');
const q = ref('');
const results = ref<FinanceRecordSnapshot[]>([]);
const error = ref<string | null>(null);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function search() {
    busy.value = true; error.value = null;
    try {
        const res = await fetch(route('finance.records.search', { type: type.value, q: q.value }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(res.status === 403 ? 'You cannot view these finance records.' : 'Search failed.');
        results.value = ((await res.json()) as { records: FinanceRecordSnapshot[] }).records;
    } catch (e) {
        error.value = (e as Error).message; results.value = [];
    } finally { busy.value = false; }
}
watch([type, q], () => { clearTimeout(timer); timer = setTimeout(search, 250); });
const link = (r: FinanceRecordSnapshot) => router.post(props.linkUrl, { source_type: r.type, source_id: r.id, ...props.extra }, { preserveScroll: true, onSuccess: () => { results.value = []; q.value = ''; } });
</script>

<template>
    <div class="space-y-2 rounded-md border border-dashed border-surface-300 p-3">
        <p class="text-xs font-medium text-surface-600">
            {{ label }} — the cost is recorded once in Finance and only linked here.
        </p>
        <div class="flex flex-wrap gap-2">
            <select
                v-model="type"
                aria-label="Finance record type"
                class="rounded-md border border-surface-300 px-2 py-1.5 text-sm"
            >
                <option
                    v-for="t in types"
                    :key="t"
                    :value="t"
                >
                    {{ t }}
                </option>
            </select>
            <input
                v-model="q"
                type="search"
                placeholder="Number, vendor, description…"
                aria-label="Search finance records"
                class="min-w-48 flex-1 rounded-md border border-surface-300 px-3 py-1.5 text-sm"
                @focus="results.length || search()"
            >
        </div>
        <p
            v-if="error"
            class="text-sm text-red-700"
            role="alert"
        >
            {{ error }}
        </p>
        <ul
            v-if="results.length"
            class="max-h-56 divide-y divide-surface-100 overflow-auto text-sm"
        >
            <li
                v-for="r in results"
                :key="`${r.type}-${r.id}`"
                class="flex items-center gap-2 py-1"
            >
                <span class="font-medium">{{ r.number }}</span>
                <span class="truncate text-surface-500">{{ r.date }} · {{ r.party ?? r.description ?? '' }}</span>
                <span class="ml-auto font-mono"><Amount :value="r.amount" /></span>
                <Button
                    variant="secondary"
                    :disabled="busy"
                    :data-test="`link-${r.type}-${r.id}`"
                    @click="link(r)"
                >
                    Link
                </Button>
            </li>
        </ul>
    </div>
</template>
