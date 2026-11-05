<script setup lang="ts">
withDefaults(defineProps<{ variant?: 'primary' | 'secondary' | 'danger' | 'dark'; type?: 'button' | 'submit'; disabled?: boolean }>(), { variant: 'primary', type: 'button', disabled: false });
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
        :class="{
            'bg-brand-600 text-white hover:bg-brand-700': variant === 'primary',
            'border border-surface-300 bg-white text-surface-700 hover:bg-surface-50': variant === 'secondary',
            'border border-red-300 bg-white text-red-700 hover:bg-red-50': variant === 'danger',
            'bg-surface-900 text-white hover:bg-surface-700': variant === 'dark',
        }"
    >
        <slot />
    </button>
</template>
