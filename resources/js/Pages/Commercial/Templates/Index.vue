<script setup lang="ts">
/** Template Library (M03-07, M03-08): versions are append-only; documents keep the version they were issued with. */
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Settings { title?: string; accent_color?: string; footer_text?: string; default_terms?: string | null; show_bank_details?: boolean }
interface Version { id: number; version: number; view_name: string; settings: Settings; notes: string | null; effective_from: string; used_by: number }
interface Template { id: number; code: string; name: string; active: boolean; versions: Version[] }
defineProps<{ templates: Template[]; can: { edit: boolean } }>();
const editing = ref<Template | null>(null);
const form = useForm({ title: '', accent_color: '#ea580c', footer_text: '', default_terms: '', show_bank_details: false, notes: '' });
function open(t: Template) {
    const s = t.versions[0]?.settings ?? {};
    form.defaults({ title: s.title ?? t.name.toUpperCase(), accent_color: s.accent_color ?? '#ea580c', footer_text: s.footer_text ?? '', default_terms: s.default_terms ?? '', show_bank_details: !!s.show_bank_details, notes: '' });
    form.reset();
    editing.value = t;
}
</script>

<template>
    <AppLayout
        title="Document templates"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Document templates' }]"
    >
        <p class="text-sm text-surface-600">
            Branding (logo, address, bank details, signatory) comes from the company profile and is frozen on each document when it is issued. Changing a template publishes a new version; documents already issued never change.
        </p>
        <div class="grid gap-4 lg:grid-cols-2">
            <Card
                v-for="t in templates"
                :key="t.id"
                :title="t.name"
                :description="`${t.code} · current v${t.versions[0]?.version ?? '—'}`"
            >
                <template #actions>
                    <a
                        v-if="t.versions[0]"
                        :href="route('commercial.templates.preview', t.versions[0].id)"
                        target="_blank"
                        rel="noopener"
                        class="text-sm font-medium text-brand-700 hover:underline"
                    >Preview PDF</a>
                    <Button
                        v-if="can.edit"
                        variant="secondary"
                        @click="open(t)"
                    >
                        New version
                    </Button>
                </template>
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="v in t.versions"
                        :key="v.id"
                        class="flex items-center gap-3 py-2"
                    >
                        <span
                            class="h-4 w-4 rounded"
                            :style="{ background: v.settings.accent_color }"
                        />
                        <span class="font-medium">v{{ v.version }}</span>
                        <span class="text-surface-500">{{ v.settings.title }} · from {{ v.effective_from.slice(0, 10) }}</span>
                        <span class="flex-1 truncate text-xs text-surface-400">{{ v.notes }}</span>
                        <StatusChip
                            :status="v.used_by ? 'issued' : 'draft'"
                            :label="`${v.used_by} issued`"
                        />
                    </li>
                </ul>
            </Card>
        </div>
        <Modal
            :open="editing !== null"
            :title="`New version of ${editing?.name ?? ''}`"
            size="lg"
            @close="editing = null"
        >
            <form
                id="tpl-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="editing && form.post(route('commercial.templates.versions.store', editing.id), { onSuccess: () => { editing = null; } })"
            >
                <FormField
                    id="t-title"
                    label="Printed title"
                    :error="form.errors.title"
                >
                    <input
                        id="t-title"
                        v-model="form.title"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="t-color"
                    label="Accent colour"
                    :error="form.errors.accent_color"
                >
                    <input
                        id="t-color"
                        v-model="form.accent_color"
                        type="color"
                        class="h-10 w-full rounded-md border border-surface-300"
                    >
                </FormField>
                <FormField
                    id="t-terms"
                    label="Default terms"
                    class="col-span-2"
                >
                    <textarea
                        id="t-terms"
                        v-model="form.default_terms"
                        rows="4"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    />
                </FormField>
                <FormField
                    id="t-footer"
                    label="Footer text"
                    class="col-span-2"
                >
                    <input
                        id="t-footer"
                        v-model="form.footer_text"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <label class="col-span-2 flex items-center gap-2 text-sm"><input
                    v-model="form.show_bank_details"
                    type="checkbox"
                > Print company bank details (payment instructions)</label>
                <FormField
                    id="t-notes"
                    label="Change note (audited)"
                    class="col-span-2"
                >
                    <input
                        id="t-notes"
                        v-model="form.notes"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="editing = null"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="tpl-form"
                    :disabled="form.processing"
                >
                    Publish version
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
