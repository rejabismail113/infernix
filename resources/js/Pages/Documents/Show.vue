<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FileDropzone from '@/Components/Form/FileDropzone.vue';
import PickerModal, { type PickerItem } from '@/Components/PickerModal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import { useLinkPicker } from '@/composables/useLinkPicker';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatBytes } from '@/lib/format';
import type { DocumentRow, EntityTypeOption } from '@/types/documents';
import type { TableColumn } from '@/types/table';

interface Version { id: number; version_no: number; file_name: string; mime_type: string; size_bytes: number; content_hash: string; scan_status: string; uploaded_by: string; created_at: string }
interface LinkRow { id: number; entity_type: string; entity_id: number; link_type: string; required_evidence: boolean; label: string }
interface ShareRow { id: number; user: string; expires_at: string | null; active: boolean; reason: string | null }
const props = defineProps<{
    document: DocumentRow & { tags: string[]; retention: string | null; retention_until: string | null; legal_hold_reason: string | null };
    versions: Version[];
    links: LinkRow[];
    shares: ShareRow[];
    entityTypes: EntityTypeOption[];
    users: { id: number; name: string }[];
    can: { edit: boolean; delete: boolean; legalHold: boolean };
}>();

const versionForm = useForm<{ file: File | null }>({ file: null });
const share = useForm({ user_id: '', expires_at: '', reason: '' });
const del = useForm({ reason: '' });
const linkForm = useForm({ entity_type: '', entity_id: '', link_type: '', required_evidence: false });
const pickerOpen = ref(false);
const pickerTab = ref<string | null>(props.entityTypes[0]?.type ?? null);
const picker = useLinkPicker();
const pending = ref<{ type: string; item: PickerItem } | null>(null);
const pendingLinkTypes = computed(() => props.entityTypes.find((t) => t.type === pending.value?.type)?.link_types ?? []);

const versionColumns: TableColumn[] = [
    { key: 'version_no', label: 'Ver.', numeric: true, sortable: true, hideable: false }, { key: 'file_name', label: 'File' },
    { key: 'size_bytes', label: 'Size', numeric: true, sortable: true }, { key: 'content_hash', label: 'SHA-256' },
    { key: 'scan_status', label: 'Scan' }, { key: 'uploaded_by', label: 'Uploaded by' }, { key: 'created_at', label: 'When', sortable: true },
];

function onPicked(item: PickerItem, tab: string | null) {
    if (!tab) return;
    pending.value = { type: tab, item };
    linkForm.entity_type = tab;
    linkForm.entity_id = String(item.id);
    linkForm.link_type = props.entityTypes.find((t) => t.type === tab)?.link_types[0] ?? '';
}
function saveLink() {
    linkForm.post(route('documents.links.store', props.document.id), { preserveScroll: true, onSuccess: () => { pending.value = null; linkForm.reset(); } });
}
function uploadVersion() {
    versionForm.post(route('documents.versions.store', props.document.id), { forceFormData: true, preserveScroll: true, onSuccess: () => versionForm.reset() });
}
function archive() {
    router.post(route('documents.archive', props.document.id), { restore: props.document.archived }, { preserveScroll: true });
}
function hold() {
    const on = !props.document.legal_hold;
    const reason = window.prompt(on ? 'Reason for legal hold (audited)' : 'Reason for releasing legal hold (audited)');
    if (reason) router.post(route('documents.legal-hold', props.document.id), { hold: on, reason }, { preserveScroll: true });
}
function destroy() {
    if (window.confirm('Permanently delete this document? Stored files are removed; this cannot be undone.')) del.delete(route('documents.destroy', props.document.id));
}
</script>

<template>
    <AppLayout
        :title="document.title"
        :status="document.classification"
        :breadcrumbs="[{ label: 'Documents', href: route('documents.index') }, { label: document.title }]"
    >
        <template #chips>
            <StatusChip
                v-if="document.frozen"
                status="frozen"
            />
            <StatusChip
                v-if="document.legal_hold"
                status="legal_hold"
            />
            <StatusChip
                v-if="document.archived"
                status="archived"
            />
        </template>
        <template #actions>
            <a
                :href="route('documents.download', document.id)"
                class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >Download v{{ document.version }}</a>
            <Button
                v-if="can.edit"
                variant="secondary"
                @click="archive"
            >
                {{ document.archived ? 'Restore' : 'Archive' }}
            </Button>
            <Button
                v-if="can.legalHold"
                variant="secondary"
                @click="hold"
            >
                {{ document.legal_hold ? 'Release hold' : 'Legal hold' }}
            </Button>
        </template>

        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Details"
                class="xl:col-span-1"
            >
                <dl class="grid grid-cols-3 gap-y-2 text-sm">
                    <dt class="text-surface-500">
                        Owner
                    </dt><dd class="col-span-2">
                        {{ document.owner }}
                    </dd>
                    <dt class="text-surface-500">
                        File
                    </dt><dd class="col-span-2">
                        {{ document.file_name }} · {{ formatBytes(document.size_bytes) }}
                    </dd>
                    <dt class="text-surface-500">
                        Retention
                    </dt><dd class="col-span-2">
                        {{ document.retention ?? 'None' }}<span
                            v-if="document.retention_until"
                            class="text-surface-500"
                        > · until {{ document.retention_until }}</span>
                    </dd>
                    <dt class="text-surface-500">
                        Tags
                    </dt><dd class="col-span-2">
                        {{ document.tags.join(', ') || '—' }}
                    </dd>
                    <template v-if="document.legal_hold">
                        <dt class="text-surface-500">
                            Hold reason
                        </dt><dd class="col-span-2">
                            {{ document.legal_hold_reason }}
                        </dd>
                    </template>
                </dl>
            </Card>

            <Card
                title="Linked records"
                class="xl:col-span-2"
            >
                <template #actions>
                    <Button
                        v-if="can.edit"
                        variant="secondary"
                        @click="pickerOpen = true; picker.search(pickerTab, '')"
                    >
                        Link to record…
                    </Button>
                </template>
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="l in links"
                        :key="l.id"
                        class="flex items-center justify-between py-2"
                    >
                        <span>{{ l.label }}</span>
                        <span class="flex gap-1"><StatusChip
                            :status="l.link_type"
                            tone="neutral"
                            :label="l.link_type.replace(/_/g, ' ')"
                        /><StatusChip
                            v-if="l.required_evidence"
                            status="required"
                            tone="brand"
                            label="Required evidence"
                        /></span>
                    </li>
                    <li
                        v-if="links.length === 0"
                        class="py-2 text-surface-500"
                    >
                        Not linked to any record.
                    </li>
                </ul>
                <form
                    v-if="pending"
                    class="mt-3 flex flex-wrap items-center gap-2 rounded-md bg-brand-50 p-3 text-sm"
                    @submit.prevent="saveLink"
                >
                    <span class="font-medium">{{ pending.item.label }}</span>
                    <select
                        v-model="linkForm.link_type"
                        aria-label="Link type"
                        class="rounded-md border border-surface-300 px-2 py-1.5"
                    >
                        <option
                            v-for="lt in pendingLinkTypes"
                            :key="lt"
                        >
                            {{ lt }}
                        </option>
                    </select>
                    <label class="flex items-center gap-1"><input
                        v-model="linkForm.required_evidence"
                        type="checkbox"
                    > required evidence</label>
                    <Button type="submit">
                        Save link
                    </Button>
                    <button
                        type="button"
                        class="text-surface-500 hover:underline"
                        @click="pending = null"
                    >
                        Cancel
                    </button>
                    <p
                        v-if="Object.keys(linkForm.errors).length"
                        class="w-full text-red-600"
                        role="alert"
                    >
                        {{ Object.values(linkForm.errors).join(' ') }}
                    </p>
                </form>
            </Card>
        </div>

        <Card title="Versions">
            <DataTable
                :columns="versionColumns"
                :rows="versions"
                prefs-key="documents.versions"
            >
                <template #cell-version_no="{ row }">
                    v{{ row.version_no }}
                </template>
                <template #cell-size_bytes="{ row }">
                    {{ formatBytes(row.size_bytes) }}
                </template>
                <template #cell-content_hash="{ row }">
                    <span
                        class="font-mono text-xs"
                        :title="row.content_hash"
                    >{{ row.content_hash.slice(0, 12) }}…</span>
                </template>
                <template #cell-scan_status="{ row }">
                    <StatusChip :status="row.scan_status" />
                </template>
                <template #cell-created_at="{ row }">
                    {{ row.created_at.slice(0, 16).replace('T', ' ') }}
                </template>
                <template #actions="{ row }">
                    <a
                        :href="route('documents.download', { document: document.id, version: row.version_no })"
                        class="text-sm font-medium text-brand-700 hover:underline"
                    >Download</a>
                </template>
            </DataTable>
            <form
                v-if="can.edit && !document.frozen"
                class="mt-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end"
                @submit.prevent="uploadVersion"
            >
                <FileDropzone
                    id="new-version"
                    v-model="versionForm.file"
                    label="Drop a new version here or browse"
                    :error="versionForm.errors.file"
                />
                <Button
                    type="submit"
                    :disabled="versionForm.processing || !versionForm.file"
                >
                    Upload new version
                </Button>
            </form>
            <p
                v-else-if="document.frozen"
                class="mt-3 text-sm text-surface-500"
            >
                This document is frozen because it belongs to an issued document; it can no longer change.
            </p>
        </Card>

        <Card
            v-if="can.edit"
            title="Explicit shares"
            description="View-only access for specific people, optionally time-limited. Audited."
        >
            <ul class="mb-3 divide-y divide-surface-100 text-sm">
                <li
                    v-for="s in shares"
                    :key="s.id"
                    class="flex items-center justify-between py-2"
                >
                    <span>{{ s.user }} <span class="text-xs text-surface-500">· {{ s.reason }}</span></span>
                    <span class="flex items-center gap-2">
                        <StatusChip
                            :status="s.active ? 'active' : 'expired'"
                            :label="s.expires_at ? (s.active ? 'until ' + s.expires_at.slice(0, 10) : 'expired') : 'no expiry'"
                        />
                        <button
                            type="button"
                            class="text-xs text-red-700 hover:underline"
                            @click="router.delete(route('documents.shares.destroy', { document: document.id, share: s.id }), { preserveScroll: true })"
                        >remove</button>
                    </span>
                </li>
                <li
                    v-if="shares.length === 0"
                    class="py-2 text-surface-500"
                >
                    Not shared with anyone.
                </li>
            </ul>
            <form
                class="flex flex-wrap gap-2 text-sm"
                @submit.prevent="share.post(route('documents.shares.store', document.id), { preserveScroll: true, onSuccess: () => share.reset() })"
            >
                <select
                    v-model="share.user_id"
                    required
                    aria-label="Share with"
                    class="rounded-md border border-surface-300 px-2 py-2"
                >
                    <option
                        value=""
                        disabled
                    >
                        Share with…
                    </option>
                    <option
                        v-for="u in users"
                        :key="u.id"
                        :value="u.id"
                    >
                        {{ u.name }}
                    </option>
                </select>
                <input
                    v-model="share.expires_at"
                    type="date"
                    aria-label="Expires"
                    class="rounded-md border border-surface-300 px-2 py-2"
                >
                <input
                    v-model="share.reason"
                    required
                    placeholder="Reason (audited)"
                    aria-label="Reason"
                    class="min-w-56 flex-1 rounded-md border border-surface-300 px-3 py-2"
                >
                <Button
                    type="submit"
                    variant="secondary"
                >
                    Share
                </Button>
                <p
                    v-if="Object.keys(share.errors).length"
                    class="w-full text-red-600"
                    role="alert"
                >
                    {{ Object.values(share.errors).join(' ') }}
                </p>
            </form>
        </Card>

        <Card
            v-if="can.delete"
            title="Permanent deletion"
            description="Owner only. Refused under legal hold, inside the retention period, or for issued documents."
        >
            <div class="flex flex-wrap gap-2">
                <input
                    v-model="del.reason"
                    placeholder="Reason (audited)"
                    aria-label="Deletion reason"
                    class="min-w-64 flex-1 rounded-md border border-surface-300 px-3 py-2 text-sm"
                >
                <Button
                    variant="danger"
                    @click="destroy"
                >
                    Delete permanently
                </Button>
            </div>
            <p
                v-if="del.errors.reason"
                class="mt-1 text-sm text-red-600"
                role="alert"
            >
                {{ del.errors.reason }}
            </p>
        </Card>

        <PickerModal
            v-model:active-tab="pickerTab"
            :open="pickerOpen"
            title="Link to a record"
            :tabs="entityTypes.map((t) => ({ key: t.type, label: t.label }))"
            :items="picker.items.value"
            :loading="picker.loading.value"
            confirm-label="Choose"
            @search="(q, tab) => picker.search(tab, q)"
            @select="onPicked"
            @close="pickerOpen = false"
        />
    </AppLayout>
</template>
