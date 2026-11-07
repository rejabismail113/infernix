<script setup lang="ts">
/** `pill`: primary call-to-action style — rounded pill with a soft shadow (e.g. "+ New Lead"). */
withDefaults(defineProps<{ variant?: 'primary' | 'secondary' | 'danger' | 'dark'; type?: 'button' | 'submit'; disabled?: boolean; pill?: boolean }>(), { variant: 'primary', type: 'button', disabled: false, pill: false });
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex items-center justify-center gap-1.5 text-sm transition disabled:cursor-not-allowed disabled:opacity-50"
        :class="{
            'rounded-md px-4 py-2 font-medium': !pill,
            'rounded-full px-5 py-2.5 font-semibold shadow-[0_6px_16px_-6px_rgba(184,74,0,0.55)]': pill,
            'bg-brand-600 text-white hover:bg-brand-700': variant === 'primary',
            'border border-surface-300 bg-white text-surface-700 hover:bg-surface-50': variant === 'secondary',
            'border border-red-300 bg-white text-red-700 hover:bg-red-50': variant === 'danger',
            'bg-surface-900 text-white hover:bg-surface-700': variant === 'dark',
        }"
    >
        <slot />
    </button>
</template>
