<script setup lang="ts">
/**
 * Status chip with a consistent tone per business status (SRS §5.1 statuses). Unknown statuses are neutral.
 */
import { computed } from 'vue';

export type Tone = 'neutral' | 'info' | 'warning' | 'success' | 'danger' | 'brand';

const TONES: Record<string, Tone> = {
    draft: 'neutral', archived: 'neutral', cancelled: 'neutral', inactive: 'neutral', superseded: 'neutral', skipped: 'neutral', open: 'info',
    submitted: 'warning', pending: 'warning', pending_approval: 'warning', partially_paid: 'warning', partially_billed: 'warning', on_hold: 'warning', expiring: 'warning',
    approved: 'success', paid: 'success', posted: 'success', settled: 'success', active: 'success', complete: 'success', clean: 'success', accepted: 'success', valid: 'success',
    rejected: 'danger', void: 'danger', infected: 'danger', expired: 'danger', deactivated: 'danger', missing: 'danger', legal_hold: 'danger', medical: 'danger',
    issued: 'info', locked: 'info', frozen: 'info', reversed: 'info', restricted: 'warning', confidential: 'neutral', public_internal: 'info',
    inventory: 'success',
};

const CLASSES: Record<Tone, string> = {
    neutral: 'bg-surface-100 text-surface-700 ring-surface-200',
    info: 'bg-sky-50 text-sky-800 ring-sky-200',
    warning: 'bg-amber-50 text-amber-800 ring-amber-200',
    success: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    danger: 'bg-red-50 text-red-800 ring-red-200',
    brand: 'bg-brand-50 text-brand-800 ring-brand-200',
};

const props = withDefaults(defineProps<{ status: string; label?: string; tone?: Tone; size?: 'sm' | 'md' }>(), { label: undefined, tone: undefined, size: 'sm' });

const resolvedTone = computed<Tone>(() => props.tone ?? TONES[props.status.toLowerCase()] ?? 'neutral');
const text = computed(() => props.label ?? props.status.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()));
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap rounded-md font-medium ring-1 ring-inset"
        :class="[CLASSES[resolvedTone], size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs']"
        :data-tone="resolvedTone"
    >{{ text }}</span>
</template>
