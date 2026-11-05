<script setup lang="ts">
/** Displays a decimal-string amount as RM with grouping; negative amounts in red. No float maths. */
import { computed } from 'vue';
import { formatMyr, isDecimalString } from '@/lib/money';

const props = defineProps<{ value: string | null | undefined; muted?: boolean }>();
const text = computed(() => (props.value !== null && props.value !== undefined && isDecimalString(props.value) ? formatMyr(props.value) : '—'));
const negative = computed(() => typeof props.value === 'string' && props.value.trim().startsWith('-'));
</script>

<template>
    <span
        class="whitespace-nowrap font-mono tabular-nums"
        :class="negative ? 'text-red-700' : muted ? 'text-surface-500' : ''"
    >{{ text }}</span>
</template>
