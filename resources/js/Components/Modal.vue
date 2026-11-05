<script setup lang="ts">
/**
 * Accessible dialog: focus moves into the dialog, Tab is trapped, Escape closes, focus returns to the
 * opener on close.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon from '@/Components/Icon.vue';

const props = withDefaults(defineProps<{ open: boolean; title: string; size?: 'md' | 'lg' | 'xl' }>(), { size: 'lg' });
const emit = defineEmits<{ (e: 'close'): void }>();

const panel = ref<HTMLElement | null>(null);
let opener: HTMLElement | null = null;
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

function focusables(): HTMLElement[] {
    return panel.value ? Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE)) : [];
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.stopPropagation();
        emit('close');
        return;
    }
    if (e.key !== 'Tab') return;
    const items = focusables();
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}

watch(() => props.open, async (open) => {
    if (open) {
        opener = document.activeElement as HTMLElement | null;
        await nextTick();
        (panel.value?.querySelector<HTMLElement>('[data-autofocus]') ?? focusables()[0])?.focus();
    } else {
        opener?.focus?.();
    }
}, { immediate: true });
onBeforeUnmount(() => opener?.focus?.());
</script>

<template>
    <Teleport
        to="body"
        :disabled="false"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-surface-900/40 p-4 pt-[8vh]"
            @mousedown.self="emit('close')"
        >
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                class="w-full rounded-xl bg-white shadow-2xl"
                :class="{ 'max-w-lg': size === 'md', 'max-w-3xl': size === 'lg', 'max-w-5xl': size === 'xl' }"
                @keydown="onKeydown"
            >
                <header class="flex items-center justify-between border-b border-surface-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">
                        {{ title }}
                    </h2>
                    <button
                        type="button"
                        class="rounded p-1 text-surface-500 hover:bg-surface-100"
                        aria-label="Close"
                        data-test="modal-close"
                        @click="emit('close')"
                    >
                        <Icon name="close" />
                    </button>
                </header>
                <div class="px-5 py-4">
                    <slot />
                </div>
                <footer
                    v-if="$slots.footer"
                    class="flex justify-end gap-2 border-t border-surface-200 px-5 py-3"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
