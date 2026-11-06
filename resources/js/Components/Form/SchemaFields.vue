<script setup lang="ts">
/**
 * Renders a grid of form fields from a schema (shared by the P3 module pages). Money fields use <MoneyInput>
 * (decimal strings only — never a float). Values are owned by the parent form; changes are emitted.
 */
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import type { FieldDef } from '@/types/operations';

const props = withDefaults(defineProps<{ fields: FieldDef[]; values: object; errors?: object; idPrefix?: string; disabled?: boolean }>(), { errors: () => ({}), idPrefix: 'f', disabled: false });
const emit = defineEmits<{ (e: 'set', key: string, value: unknown): void }>();
const val = (k: string): unknown => (props.values as Record<string, unknown>)[k];
const err = (k: string): string | undefined => (props.errors as Record<string, string | undefined>)[k];
const text = (k: string): string => { const v = val(k); return v === null || v === undefined ? '' : String(v); };
const input = (e: Event): string => (e.target as HTMLInputElement).value;
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div
            v-for="f in fields"
            :key="f.key"
            :class="f.span === 2 || f.type === 'textarea' ? 'sm:col-span-2' : ''"
        >
            <label
                v-if="f.type === 'checkbox'"
                class="mt-6 flex items-center gap-2 text-sm"
            >
                <input
                    :id="`${idPrefix}-${f.key}`"
                    type="checkbox"
                    :checked="Boolean(val(f.key))"
                    :disabled="disabled"
                    @change="emit('set', f.key, ($event.target as HTMLInputElement).checked)"
                >
                {{ f.label }}
            </label>
            <FormField
                v-else
                :id="`${idPrefix}-${f.key}`"
                :label="f.label + (f.required ? ' *' : '')"
                :error="err(f.key)"
            >
                <MoneyInput
                    v-if="f.type === 'money'"
                    :id="`${idPrefix}-${f.key}`"
                    :model-value="text(f.key)"
                    :disabled="disabled"
                    :invalid="!!err(f.key)"
                    @update:model-value="(v) => emit('set', f.key, v)"
                />
                <select
                    v-else-if="f.type === 'select'"
                    :id="`${idPrefix}-${f.key}`"
                    :value="val(f.key) ?? ''"
                    :disabled="disabled"
                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    @change="emit('set', f.key, (($event.target as HTMLSelectElement).value === '' ? '' : (f.options ?? []).find((o) => String(o.value) === ($event.target as HTMLSelectElement).value)?.value ?? ''))"
                >
                    <option value="">
                        —
                    </option>
                    <option
                        v-for="o in f.options ?? []"
                        :key="o.value"
                        :value="o.value"
                    >
                        {{ o.label }}
                    </option>
                </select>
                <textarea
                    v-else-if="f.type === 'textarea'"
                    :id="`${idPrefix}-${f.key}`"
                    :value="text(f.key)"
                    :disabled="disabled"
                    rows="3"
                    :placeholder="f.placeholder"
                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    @input="emit('set', f.key, input($event))"
                />
                <input
                    v-else
                    :id="`${idPrefix}-${f.key}`"
                    :type="f.type === 'number' ? 'number' : f.type"
                    :value="text(f.key)"
                    :disabled="disabled"
                    :placeholder="f.placeholder"
                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    @input="emit('set', f.key, input($event))"
                >
                <p
                    v-if="f.help"
                    class="mt-1 text-xs text-surface-500"
                >
                    {{ f.help }}
                </p>
            </FormField>
        </div>
    </div>
</template>
