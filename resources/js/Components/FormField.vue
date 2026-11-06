<script setup lang="ts">
defineProps<{ label: string; error?: string; id: string }>();
</script>

<template>
    <div>
        <label
            :for="id"
            class="mb-1 block text-sm font-medium"
        >{{ label }}</label>
        <slot />
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
