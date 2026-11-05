<script setup lang="ts">
/**
 * Drag & drop / click-to-browse file picker. Client-side checks (extension, size) are for usability only;
 * the server re-validates by content (G-07).
 */
import { computed, ref } from 'vue';
import Icon from '@/Components/Icon.vue';
import { formatBytes } from '@/lib/format';

const props = withDefaults(defineProps<{
    modelValue: File | null;
    accept?: string[];
    maxBytes?: number;
    id?: string;
    label?: string;
    error?: string;
}>(), { accept: () => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'docx', 'csv'], maxBytes: 25 * 1024 * 1024, id: 'file', label: 'Drop a file here or browse', error: undefined });

const emit = defineEmits<{ (e: 'update:modelValue', file: File | null): void }>();
const dragging = ref(false);
const localError = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);
const acceptAttr = computed(() => props.accept.map((e) => `.${e}`).join(','));

function take(file: File | undefined | null) {
    localError.value = null;
    if (!file) return;
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!props.accept.includes(ext)) { localError.value = `.${ext} files are not allowed (${props.accept.join(', ')}).`; return; }
    if (file.size > props.maxBytes) { localError.value = `File is larger than ${formatBytes(props.maxBytes)}.`; return; }
    emit('update:modelValue', file);
}

function onDrop(e: DragEvent) {
    dragging.value = false;
    take(e.dataTransfer?.files?.[0]);
}
</script>

<template>
    <div>
        <div
            class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm transition"
            :class="dragging ? 'border-brand-500 bg-brand-50' : (error || localError) ? 'border-red-300 bg-red-50/40' : 'border-surface-300 bg-surface-50 hover:border-brand-300'"
            role="button"
            tabindex="0"
            :aria-describedby="`${id}-hint`"
            data-test="dropzone"
            @click="input?.click()"
            @keydown.enter.prevent="input?.click()"
            @keydown.space.prevent="input?.click()"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <Icon
                name="upload"
                :size="22"
                class="text-brand-600"
            />
            <span class="font-medium text-surface-700">{{ modelValue ? modelValue.name : label }}</span>
            <span
                :id="`${id}-hint`"
                class="text-xs text-surface-500"
            >{{ modelValue ? formatBytes(modelValue.size) : accept.join(', ').toUpperCase() + ' · max ' + formatBytes(maxBytes) }}</span>
            <input
                :id="id"
                ref="input"
                type="file"
                class="sr-only"
                :accept="acceptAttr"
                @change="take(($event.target as HTMLInputElement).files?.[0])"
            >
        </div>
        <p
            v-if="error || localError"
            class="mt-1 text-sm text-red-600"
            role="alert"
        >
            {{ error ?? localError }}
        </p>
    </div>
</template>
