<script setup lang="ts">
/** "ⓘ" disclosure showing a metric's registered definition, source and rule (report_metrics, M11). */
defineProps<{ metric?: { name: string; definition: string; source: string; rule: string } }>();
</script>

<template>
    <details
        v-if="metric"
        class="text-xs text-surface-500"
    >
        <summary class="cursor-pointer select-none hover:text-surface-700">
            How is this calculated?
        </summary>
        <p class="mt-1">
            {{ metric.definition }}
        </p>
        <p class="mt-1">
            <span class="font-medium">Source:</span> {{ metric.source }} · <span class="font-medium">Rule:</span> {{ metric.rule }}
        </p>
    </details>
</template>
