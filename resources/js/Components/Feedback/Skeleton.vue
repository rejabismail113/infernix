<script setup lang="ts">
withDefaults(defineProps<{ lines?: number }>(), { lines: 3 });
</script>

<template>
    <div
        class="space-y-2"
        aria-busy="true"
        aria-label="Loading"
    >
        <div
            v-for="n in lines"
            :key="n"
            class="h-3 animate-pulse rounded bg-surface-200"
            :style="{ width: `${100 - ((n * 13) % 40)}%` }"
        />
    </div>
</template>
