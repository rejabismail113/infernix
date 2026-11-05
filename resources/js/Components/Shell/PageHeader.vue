<script setup lang="ts">
import StatusChip from '@/Components/StatusChip.vue';

withDefaults(defineProps<{ title: string; status?: string | null; statusLabel?: string }>(), { status: null, statusLabel: undefined });
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight text-surface-900">
                {{ title }}
            </h1>
            <StatusChip
                v-if="status"
                :status="status"
                :label="statusLabel"
                size="md"
            />
            <slot name="chips" />
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
