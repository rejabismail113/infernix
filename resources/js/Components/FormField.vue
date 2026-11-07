<script setup lang="ts">
defineProps<{ label: string; error?: string; id: string; required?: boolean; hint?: string }>();
</script>

<template>
    <div>
        <label
            :for="id"
            class="mb-1 block text-sm font-medium"
        >{{ label }}<span
            v-if="required"
            class="ml-0.5 text-red-600"
            aria-hidden="true"
        >*</span></label>
        <slot />
        <p
            v-if="hint && !error"
            class="mt-1 text-xs text-surface-500"
        >
            {{ hint }}
        </p>
        <p
            v-if="error"
            :id="`${id}-error`"
            class="mt-1 text-sm text-red-600"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
