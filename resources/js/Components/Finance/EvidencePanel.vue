<script setup lang="ts">
/** Linked evidence for a finance record + upload in place (stored in the Document Centre, M01-09). */
import { useForm } from '@inertiajs/vue3';
import FileDropzone from '@/Components/Form/FileDropzone.vue';
import Button from '@/Components/Button.vue';
import type { LinkedDoc } from '@/types/finance';

const props = withDefaults(defineProps<{ entityType: string; entityId: number; linkType?: string; documents: LinkedDoc[]; canAttach?: boolean; classification?: string; compact?: boolean }>(), {
    linkType: 'receipt', canAttach: false, classification: 'confidential', compact: false,
});
const form = useForm<{ file: File | null; stay: boolean; classification: string; entity_type: string; entity_id: number; link_type: string; retention_rule: string }>({
    file: null, stay: true, classification: props.classification, entity_type: props.entityType, entity_id: props.entityId, link_type: props.linkType, retention_rule: 'finance_7y',
});
function upload() {
    form.post(route('documents.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset('file') });
}
</script>

<template>
    <div class="space-y-2">
        <ul
            v-if="documents.length"
            class="space-y-1 text-sm"
        >
            <li
                v-for="d in documents"
                :key="d.document_id"
                class="flex items-center gap-2"
            >
                <span class="rounded bg-surface-100 px-1.5 py-0.5 text-xs text-surface-600">{{ d.link_type }}</span>
                <a
                    :href="route('documents.download', d.document_id)"
                    class="text-brand-700 hover:underline"
                >{{ d.file_name ?? d.title }}</a>
            </li>
        </ul>
        <p
            v-else-if="!canAttach || compact"
            class="text-sm text-surface-500"
        >
            No {{ linkType }} attached.
        </p>
        <form
            v-if="canAttach"
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="upload"
        >
            <div class="min-w-64 flex-1">
                <FileDropzone
                    :id="`evidence-${entityType}-${entityId}`"
                    v-model="form.file"
                    :label="`Attach ${linkType}`"
                    :error="form.errors.file ?? form.errors.entity_id"
                />
            </div>
            <Button
                type="submit"
                variant="secondary"
                :disabled="!form.file || form.processing"
            >
                Upload
            </Button>
        </form>
    </div>
</template>
