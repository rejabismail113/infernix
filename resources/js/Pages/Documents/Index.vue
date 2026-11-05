<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import FileDropzone from '@/Components/Form/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PickerModal, { type PickerItem } from '@/Components/PickerModal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { useLinkPicker } from '@/composables/useLinkPicker';
import { toPagination, useServerTable, type LaravelPage } from '@/composables/useServerTable';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatBytes } from '@/lib/format';
import type { DocumentRow, EntityTypeOption } from '@/types/documents';
import type { TableColumn } from '@/types/table';

const props = defineProps<{
    documents: LaravelPage<DocumentRow>;
    filters: Record<string, string | number | boolean | null>;
    classifications: { value: string; label: string }[];
    entityTypes: EntityTypeOption[];
    retentionRules: { code: string; name: string }[];
    can: { create: boolean; export: boolean };
}>();

const f = reactive({
    q: (props.filters.q as string) ?? '',
    classification: (props.filters.classification as string) ?? '',
    entity_type: (props.filters.entity_type as string) ?? '',
    include_archived: Boolean(props.filters.include_archived),
});
const table = useServerTable(() => route('documents.index'), () => props.filters);
const search = () => table.visit({ ...f, include_archived: f.include_archived ? 1 : '', page: 1 });

const columns: TableColumn[] = [
    { key: 'title', label: 'Title', sortable: true, hideable: false },
    { key: 'file_name', label: 'File' },
    { key: 'classification', label: 'Classification', sortable: true },
    { key: 'version', label: 'Ver.', sortable: true, numeric: true },
    { key: 'links', label: 'Linked to' },
    { key: 'owner', label: 'Owner' },
    { key: 'uploaded_at', label: 'Uploaded', sortable: true },
];

// --- upload with optional link (picker) ---
const uploadOpen = ref(false);
const pickerOpen = ref(false);
const pickerTab = ref<string | null>(props.entityTypes[0]?.type ?? null);
const picker = useLinkPicker();
const linked = ref<{ type: string; item: PickerItem } | null>(null);
const upload = useForm<{ file: File | null; title: string; classification: string; retention_rule: string; entity_type: string; entity_id: string; link_type: string }>({
    file: null, title: '', classification: 'confidential', retention_rule: '', entity_type: '', entity_id: '', link_type: '',
});
const linkTypes = computed(() => props.entityTypes.find((t) => t.type === linked.value?.type)?.link_types ?? []);
function openPicker() {
    pickerOpen.value = true;
    picker.search(pickerTab.value, '');
}
function onPicked(item: PickerItem, tab: string | null) {
    if (!tab) return;
    linked.value = { type: tab, item };
    upload.entity_type = tab;
    upload.entity_id = String(item.id);
    upload.link_type = props.entityTypes.find((t) => t.type === tab)?.link_types[0] ?? '';
}
function clearLink() {
    linked.value = null;
    upload.entity_type = '';
    upload.entity_id = '';
    upload.link_type = '';
}
function submit() {
    upload.transform((d) => Object.fromEntries(Object.entries(d).filter(([, v]) => v !== '' && v !== null))).post(route('documents.store'), { forceFormData: true });
}
</script>

<template>
    <AppLayout
        title="Documents"
        :breadcrumbs="[{ label: 'Documents' }]"
    >
        <template #chips>
            <StatusChip
                status="info"
                tone="info"
                :label="`${documents.total} accessible`"
            />
        </template>
        <template #actions>
            <Link
                :href="route('documents.reports.inventory')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Reports
            </Link>
            <Button
                v-if="can.create"
                @click="uploadOpen = true"
            >
                Upload document
            </Button>
        </template>

        <form
            class="flex flex-wrap items-center gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="search"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Search title, file name or tag"
                aria-label="Search documents"
                class="w-72 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.classification"
                aria-label="Classification"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any classification
                </option>
                <option
                    v-for="c in classifications"
                    :key="c.value"
                    :value="c.value"
                >
                    {{ c.label }}
                </option>
            </select>
            <select
                v-model="f.entity_type"
                aria-label="Linked to"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Linked to anything
                </option>
                <option
                    v-for="t in entityTypes"
                    :key="t.type"
                    :value="t.type"
                >
                    {{ t.label }}
                </option>
            </select>
            <label class="flex items-center gap-1.5"><input
                v-model="f.include_archived"
                type="checkbox"
            > Include archived</label>
            <Button type="submit">
                Search
            </Button>
        </form>

        <DataTable
            :columns="columns"
            :rows="documents.data"
            :pagination="toPagination(documents)"
            :sort="table.sort.value"
            :loading="table.loading.value"
            :export-url="can.export ? route('documents.reports.inventory.export') : null"
            prefs-key="documents.index"
            caption="Documents"
            empty-text="No documents you can access match these filters."
            @sort="table.onSort"
            @page="table.onPage"
            @per-page="table.onPerPage"
        >
            <template #cell-title="{ row }">
                <Link
                    :href="route('documents.show', row.id)"
                    class="inline-block min-w-40 font-medium text-brand-700 hover:underline"
                >
                    {{ row.title }}
                </Link>
                <span class="ml-1 inline-flex gap-1 align-middle">
                    <StatusChip
                        v-if="row.frozen"
                        status="frozen"
                    />
                    <StatusChip
                        v-if="row.legal_hold"
                        status="legal_hold"
                    />
                    <StatusChip
                        v-if="row.archived"
                        status="archived"
                    />
                </span>
            </template>
            <template #cell-file_name="{ row }">
                <span class="text-surface-600">{{ row.file_name }}</span> <span class="text-xs text-surface-400">{{ formatBytes(row.size_bytes) }}</span>
            </template>
            <template #cell-classification="{ row }">
                <StatusChip :status="row.classification" />
            </template>
            <template #cell-version="{ row }">
                v{{ row.version }}
            </template>
            <template #cell-links="{ row }">
                <span class="text-xs text-surface-600">{{ row.links.join(', ') || '—' }}</span>
            </template>
            <template #cell-uploaded_at="{ row }">
                <span class="whitespace-nowrap">{{ row.uploaded_at.slice(0, 10) }}</span>
            </template>
            <template #actions="{ row }">
                <a
                    :href="route('documents.download', row.id)"
                    class="text-sm font-medium text-brand-700 hover:underline"
                >Download</a>
            </template>
        </DataTable>

        <Modal
            :open="uploadOpen"
            title="Upload a document"
            size="lg"
            @close="uploadOpen = false"
        >
            <form
                id="upload-form"
                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                @submit.prevent="submit"
            >
                <div class="md:col-span-2">
                    <FileDropzone
                        v-model="upload.file"
                        :error="upload.errors.file"
                    />
                </div>
                <FormField
                    id="title"
                    label="Title (optional)"
                    :error="upload.errors.title"
                >
                    <input
                        id="title"
                        v-model="upload.title"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="classification"
                    label="Classification"
                    :error="upload.errors.classification"
                >
                    <select
                        id="classification"
                        v-model="upload.classification"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="c in classifications"
                            :key="c.value"
                            :value="c.value"
                        >
                            {{ c.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="retention_rule"
                    label="Retention"
                    :error="upload.errors.retention_rule"
                >
                    <select
                        id="retention_rule"
                        v-model="upload.retention_rule"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            Default (7 years)
                        </option>
                        <option
                            v-for="r in retentionRules"
                            :key="r.code"
                            :value="r.code"
                        >
                            {{ r.name }}
                        </option>
                        <option value="none">
                            No retention period
                        </option>
                    </select>
                </FormField>
                <div>
                    <p class="mb-1 text-sm font-medium">
                        Link to a record (optional)
                    </p>
                    <div
                        v-if="linked"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <StatusChip
                            status="info"
                            tone="brand"
                            :label="linked.item.label"
                        />
                        <select
                            v-model="upload.link_type"
                            aria-label="Link type"
                            class="rounded-md border border-surface-300 px-2 py-1.5 text-sm"
                        >
                            <option
                                v-for="lt in linkTypes"
                                :key="lt"
                            >
                                {{ lt }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="text-sm text-surface-500 hover:underline"
                            @click="clearLink"
                        >
                            Remove
                        </button>
                    </div>
                    <Button
                        v-else
                        variant="secondary"
                        @click="openPicker"
                    >
                        Choose record…
                    </Button>
                    <p
                        v-if="upload.errors.entity_id || upload.errors.entity_type"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ upload.errors.entity_id ?? upload.errors.entity_type }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="uploadOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="upload-form"
                    :disabled="upload.processing || !upload.file"
                >
                    Upload
                </Button>
            </template>
        </Modal>

        <PickerModal
            v-model:active-tab="pickerTab"
            :open="pickerOpen"
            title="Link to a record"
            :tabs="entityTypes.map((t) => ({ key: t.type, label: t.label }))"
            :items="picker.items.value"
            :loading="picker.loading.value"
            confirm-label="Link"
            @search="(q, tab) => picker.search(tab, q)"
            @select="onPicked"
            @close="pickerOpen = false"
        />
    </AppLayout>
</template>
