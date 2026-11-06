<script setup lang="ts">
/** Finance records LINKED to an operational record (they are the money; nothing is copied — rule 12). */
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import type { FinanceRecordSnapshot } from '@/types/operations';

defineProps<{ records: FinanceRecordSnapshot[]; empty?: string }>();
</script>

<template>
    <ul
        v-if="records.length"
        class="divide-y divide-surface-100 text-sm"
    >
        <li
            v-for="r in records"
            :key="`${r.type}-${r.id}`"
            class="flex flex-wrap items-center gap-2 py-1.5"
        >
            <span class="rounded bg-surface-100 px-1.5 py-0.5 text-xs text-surface-600">{{ r.purpose ?? r.type }}</span>
            <a
                :href="r.url"
                class="font-medium text-brand-700 hover:underline"
            >{{ r.number }}</a>
            <span class="text-surface-500">{{ r.date }} · {{ r.party ?? '—' }}</span>
            <StatusChip :status="r.status" />
            <span class="ml-auto font-mono"><Amount :value="r.amount" /></span>
        </li>
    </ul>
    <p
        v-else
        class="text-sm text-surface-500"
    >
        {{ empty ?? 'No finance records linked yet.' }}
    </p>
</template>
