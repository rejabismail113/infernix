<script setup lang="ts">
import StatusChip from '@/Components/StatusChip.vue';

withDefaults(defineProps<{ title: string; subtitle?: string | null; status?: string | null; statusLabel?: string }>(), { subtitle: null, status: null, statusLabel: undefined });
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
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
            <p
                v-if="subtitle"
                class="mt-1.5 max-w-3xl text-sm leading-relaxed text-surface-900/60"
                data-test="page-subtitle"
            >
                {{ subtitle }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
