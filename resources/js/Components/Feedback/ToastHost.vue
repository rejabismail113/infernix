<script setup lang="ts">
/** Renders toasts; server flash messages (success/error) are turned into toasts on every page visit. */
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import Icon from '@/Components/Icon.vue';
import { useToasts } from '@/composables/useToasts';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const toasts = useToasts();

watch(() => page.props.flash, (flash) => {
    if (flash?.success) toasts.success(flash.success);
    if (flash?.error) toasts.error(flash.error);
}, { immediate: true, deep: true });
</script>

<template>
    <div
        class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2"
        aria-live="polite"
        role="status"
    >
        <div
            v-for="t in toasts.items"
            :key="t.id"
            class="pointer-events-auto flex items-start gap-2 rounded-lg border bg-white p-3 text-sm shadow-lg"
            :class="t.kind === 'error' ? 'border-red-200' : t.kind === 'success' ? 'border-emerald-200' : 'border-surface-200'"
            :role="t.kind === 'error' ? 'alert' : undefined"
        >
            <Icon
                :name="t.kind === 'error' ? 'alert' : 'check'"
                :class="t.kind === 'error' ? 'text-red-600' : 'text-emerald-600'"
            />
            <p class="flex-1">
                {{ t.message }}
            </p>
            <button
                type="button"
                class="rounded p-0.5 text-surface-400 hover:bg-surface-100"
                aria-label="Dismiss"
                @click="toasts.dismiss(t.id)"
            >
                <Icon
                    name="close"
                    :size="14"
                />
            </button>
        </div>
    </div>
</template>
