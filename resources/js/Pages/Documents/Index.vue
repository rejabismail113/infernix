<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatBytes } from '@/lib/format';
import type { DocumentRow, EntityTypeOption } from '@/types/documents';

interface Paginated { data: DocumentRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number }
const props = defineProps<{
    documents: Paginated;
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
const search = () => router.get(route('documents.index'), Object.fromEntries(Object.entries({ ...f, include_archived: f.include_archived ? 1 : '' }).filter(([, v]) => v !== '')), { preserveState: true });

const upload = useForm<{ file: File | null; title: string; classification: string; retention_rule: string; entity_type: string; entity_id: string; link_type: string }>({
    file: null, title: '', classification: 'confidential', retention_rule: '', entity_type: '', entity_id: '', link_type: '',
});
const linkTypes = computed(() => props.entityTypes.find((t) => t.type === upload.entity_type)?.link_types ?? []);
const label = (l: string) => l.replace('&laquo;', '«').replace('&raquo;', '»');
function submit() {
    upload.transform((d) => Object.fromEntries(Object.entries(d).filter(([, v]) => v !== '' && v !== null))).post(route('documents.store'), { forceFormData: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Documents" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Documents <span class="text-sm font-normal text-surface-500">({{ documents.total }})</span>
            </h1>
        </template>

        <form
            class="mb-4 flex flex-wrap gap-2 text-sm"
            role="search"
            @submit.prevent="search"
        >
            <input
                v-model="f.q"
                placeholder="Search title, file name, tag"
                aria-label="Search"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.classification"
                aria-label="Classification"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    any classification
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
                    linked to anything
                </option>
                <option
                    v-for="t in entityTypes"
                    :key="t.type"
                    :value="t.type"
                >
                    {{ t.label }}
                </option>
            </select>
            <label class="flex items-center gap-1"><input
                v-model="f.include_archived"
                type="checkbox"
            > include archived</label>
            <button
                type="submit"
                class="rounded-md bg-brand-600 px-3 py-2 text-white hover:bg-brand-700"
            >
                Search
            </button>
            <Link
                :href="route('documents.reports.inventory')"
                class="rounded-md border border-surface-300 px-3 py-2 hover:bg-surface-100"
            >
                Reports
            </Link>
        </form>

        <div class="overflow-x-auto rounded-lg border border-surface-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-surface-100 text-left text-xs uppercase text-surface-500">
                    <tr>
                        <th class="px-3 py-2">
                            Title
                        </th><th class="px-3 py-2">
                            File
                        </th><th class="px-3 py-2">
                            Classification
                        </th><th class="px-3 py-2">
                            Ver.
                        </th><th class="px-3 py-2">
                            Linked to
                        </th><th class="px-3 py-2">
                            Owner
                        </th><th class="px-3 py-2">
                            Uploaded
                        </th><th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="d in documents.data"
                        :key="d.id"
                        class="border-t border-surface-100"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="route('documents.show', d.id)"
                                class="font-medium text-brand-700 hover:underline"
                            >
                                {{ d.title }}
                            </Link>
                            <span
                                v-if="d.frozen"
                                class="ml-1 rounded bg-surface-200 px-1 text-xs"
                            >frozen</span>
                            <span
                                v-if="d.legal_hold"
                                class="ml-1 rounded bg-red-100 px-1 text-xs text-red-700"
                            >legal hold</span>
                            <span
                                v-if="d.archived"
                                class="ml-1 rounded bg-surface-200 px-1 text-xs"
                            >archived</span>
                        </td>
                        <td class="px-3 py-2 text-surface-600">
                            {{ d.file_name }} · {{ formatBytes(d.size_bytes) }}
                        </td>
                        <td class="px-3 py-2">
                            {{ d.classification }}
                        </td>
                        <td class="px-3 py-2">
                            v{{ d.version }}
                        </td>
                        <td class="px-3 py-2 text-xs text-surface-600">
                            {{ d.links.join(', ') || '—' }}
                        </td>
                        <td class="px-3 py-2">
                            {{ d.owner }}
                        </td>
                        <td class="px-3 py-2 text-xs">
                            {{ d.uploaded_at.slice(0, 10) }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <a
                                :href="route('documents.download', d.id)"
                                class="text-brand-700 hover:underline"
                            >Download</a>
                        </td>
                    </tr>
                    <tr v-if="documents.data.length === 0">
                        <td
                            colspan="8"
                            class="px-3 py-6 text-center text-surface-400"
                        >
                            No documents you can access match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            class="mt-3 flex flex-wrap gap-1 text-sm"
            aria-label="Pagination"
        >
            <template
                v-for="l in documents.links"
                :key="l.label"
            >
                <Link
                    v-if="l.url"
                    :href="l.url"
                    class="rounded border px-2 py-1"
                    :class="l.active ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-surface-200'"
                >
                    {{ label(l.label) }}
                </Link>
            </template>
        </nav>

        <section
            v-if="can.create"
            class="mt-8 max-w-2xl rounded-lg border border-surface-200 bg-white p-4"
        >
            <h2 class="mb-3 font-semibold">
                Upload a document
            </h2>
            <form
                class="grid grid-cols-1 gap-3 md:grid-cols-2"
                @submit.prevent="submit"
            >
                <FormField
                    id="file"
                    label="File (pdf, jpg, png, xlsx, docx, csv)"
                    :error="upload.errors.file"
                    class="md:col-span-2"
                >
                    <input
                        id="file"
                        type="file"
                        required
                        accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx,.csv"
                        class="w-full text-sm"
                        @input="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    >
                </FormField>
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
                >
                    <select
                        id="retention_rule"
                        v-model="upload.retention_rule"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            default
                        </option>
                        <option
                            v-for="r in retentionRules"
                            :key="r.code"
                            :value="r.code"
                        >
                            {{ r.name }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="entity_type"
                    label="Link to (optional)"
                    :error="upload.errors.entity_type"
                >
                    <select
                        id="entity_type"
                        v-model="upload.entity_type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            not linked
                        </option>
                        <option
                            v-for="t in entityTypes"
                            :key="t.type"
                            :value="t.type"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                </FormField>
                <template v-if="upload.entity_type">
                    <FormField
                        id="entity_id"
                        label="Record id"
                        :error="upload.errors.entity_id"
                    >
                        <input
                            id="entity_id"
                            v-model="upload.entity_id"
                            inputmode="numeric"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="link_type"
                        label="Link type"
                        :error="upload.errors.link_type"
                    >
                        <select
                            id="link_type"
                            v-model="upload.link_type"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                            <option
                                v-for="lt in linkTypes"
                                :key="lt"
                            >
                                {{ lt }}
                            </option>
                        </select>
                    </FormField>
                </template>
                <button
                    type="submit"
                    :disabled="upload.processing || !upload.file"
                    class="rounded-md bg-brand-600 px-4 py-2 text-white hover:bg-brand-700 disabled:opacity-50 md:col-span-2"
                >
                    Upload
                </button>
            </form>
        </section>
    </AppLayout>
</template>
