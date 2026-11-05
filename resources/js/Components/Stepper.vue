<script setup lang="ts">
/** Stepper header for multi-step flows ("1. Builder › 2. Review"). Completed steps are clickable. */
export interface Step { key: string; label: string }

const props = defineProps<{ steps: Step[]; current: string }>();
const emit = defineEmits<{ (e: 'select', key: string): void }>();

const index = (key: string) => props.steps.findIndex((s) => s.key === key);
</script>

<template>
    <nav aria-label="Progress">
        <ol class="flex flex-wrap items-center gap-2">
            <li
                v-for="(step, i) in steps"
                :key="step.key"
                class="flex items-center gap-2"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition"
                    :class="step.key === current
                        ? 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200'
                        : i < index(current)
                            ? 'text-surface-700 hover:bg-surface-100'
                            : 'cursor-default text-surface-400'"
                    :aria-current="step.key === current ? 'step' : undefined"
                    :disabled="i > index(current)"
                    @click="i < index(current) && emit('select', step.key)"
                >
                    <span
                        class="flex h-5 w-5 items-center justify-center rounded-full text-xs"
                        :class="step.key === current ? 'bg-brand-600 text-white' : i < index(current) ? 'bg-emerald-600 text-white' : 'bg-surface-200 text-surface-500'"
                    >{{ i < index(current) ? '✓' : i + 1 }}</span>
                    {{ step.label }}
                </button>
                <span
                    v-if="i < steps.length - 1"
                    class="text-surface-300"
                    aria-hidden="true"
                >›</span>
            </li>
        </ol>
    </nav>
</template>
