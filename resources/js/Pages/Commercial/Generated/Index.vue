<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Row { id: number; type: string; label: string; number: string; client: string; subject: string | null; status: string; issue_date: string | null }
const props = defineProps<{ documents: Row[]; filters: Record<string, string | null>; types: { value: string; label: string; create: boolean }[] }>();
const f = reactive({ type: props.filters.type ?? '', q: props.filters.q ?? '' });
</script>

<template>
    <AppLayout
        title="Letters, memos & certificates"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Letters & memos' }]"
    >
        <template #actions>
            <Link
                v-for="t in types.filter((x) => x.create)"
                :key="t.value"
                :href="route('commercial.generated.create', { type: t.value })"
                class="rounded-md border border-brand-300 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                :data-test="`new-${t.value}`"
            >
                New {{ t.label.toLowerCase() }}
            </Link>
        </template>
        <form
            class="flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('commercial.generated.index'), { type: f.type || undefined, q: f.q || undefined }, { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Number, subject or recipient"
                aria-label="Search"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.type"
                aria-label="Type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    All types
                </option>
                <option
                    v-for="t in types"
                    :key="t.value"
                    :value="t.value"
                >
                    {{ t.label }}
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="[{ key: 'number', label: 'Number', hideable: false }, { key: 'label', label: 'Type' }, { key: 'client', label: 'Recipient' }, { key: 'subject', label: 'Subject / course' }, { key: 'issue_date', label: 'Issued' }, { key: 'status', label: 'Status' }]"
            :rows="documents"
            empty-text="Nothing generated yet."
        >
            <template #cell-number="{ row }">
                <Link
                    :href="route('commercial.generated.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.number }}
                </Link>
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
    </AppLayout>
</template>
