<script setup lang="ts">
/**
 * MYR amount input. NEVER emits a JS float:
 *  - mode "decimal" (default): emits a decimal string like "1234.50" (or '' when empty)
 *  - mode "minor": emits integer minor units (sen), e.g. 123450 (or null when empty)
 * Typing is restricted to digits, one decimal point (max 2 decimals) and an optional leading minus.
 * (CLAUDE.md rule 1)
 */
import { computed, ref, watch } from 'vue';
import { fromMinorUnits, isDecimalString, toMinorUnits } from '@/lib/money';

const props = withDefaults(defineProps<{
    modelValue: string | number | null;
    mode?: 'decimal' | 'minor';
    allowNegative?: boolean;
    id?: string;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
}>(), { mode: 'decimal', allowNegative: false, id: undefined, placeholder: '0.00', disabled: false, invalid: false });

const emit = defineEmits<{ (e: 'update:modelValue', value: string | number | null): void }>();

function toText(v: string | number | null): string {
    if (v === null || v === '') return '';
    if (typeof v === 'number') return Number.isInteger(v) ? fromMinorUnits(v) : '';
    return isDecimalString(v) ? fromMinorUnits(toMinorUnits(v)) : v;
}

const text = ref(toText(props.modelValue));
watch(() => props.modelValue, (v) => {
    if (v === null || v === '') { if (text.value !== '' && text.value !== '-') text.value = ''; return; }
    const current = sanitize(text.value);
    const incoming = typeof v === 'number' ? v : (isDecimalString(v) ? toMinorUnits(v) : null);
    if (incoming !== null && isDecimalString(current) && toMinorUnits(current) === incoming) return;
    text.value = toText(v);
});

function sanitize(raw: string): string {
    let s = raw.replace(/[^0-9.-]/g, '');
    const negative = props.allowNegative && s.startsWith('-');
    s = s.replace(/-/g, '');
    const [whole, ...rest] = s.split('.');
    let out = whole.replace(/^0+(?=\d)/, '');
    if (rest.length) out += '.' + rest.join('').slice(0, 2);
    return (negative ? '-' : '') + out;
}

function emitFrom(clean: string) {
    if (clean === '' || clean === '-' || clean === '.' || clean === '-.') {
        emit('update:modelValue', props.mode === 'minor' ? null : '');
        return;
    }
    const normalized = clean.endsWith('.') ? clean.slice(0, -1) : clean;
    const withLead = normalized.startsWith('.') ? '0' + normalized : normalized.replace(/^-\./, '-0.');
    if (!isDecimalString(withLead)) return;
    const minor = toMinorUnits(withLead);
    emit('update:modelValue', props.mode === 'minor' ? minor : fromMinorUnits(minor));
}

function onInput(e: Event) {
    const el = e.target as HTMLInputElement;
    const clean = sanitize(el.value);
    text.value = clean;
    el.value = clean;
    emitFrom(clean);
}

function onBlur() {
    const clean = sanitize(text.value);
    if (clean === '' || clean === '-') { text.value = ''; return; }
    const lead = clean.startsWith('.') ? '0' + clean : clean;
    const normalized = lead.endsWith('.') ? lead.slice(0, -1) : lead;
    if (isDecimalString(normalized)) text.value = fromMinorUnits(toMinorUnits(normalized));
}

const describedBy = computed(() => (props.id ? `${props.id}-currency` : undefined));
</script>

<template>
    <div
        class="glass-field flex min-h-[2.6rem] items-stretch overflow-hidden"
        :class="{ 'glass-invalid': invalid }"
    >
        <span
            :id="describedBy"
            class="flex items-center border-r border-surface-900/5 bg-surface-100/60 px-3 text-sm text-surface-500"
        >RM</span>
        <input
            :id="id"
            :value="text"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedBy"
            class="field-bare w-full border-0 bg-transparent px-3 py-2 text-right font-mono tabular-nums outline-none focus:ring-0"
            data-test="money-input"
            @input="onInput"
            @blur="onBlur"
        >
    </div>
</template>
