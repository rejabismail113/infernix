<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatBytes } from '@/lib/format';
import type { DocumentRow, EntityTypeOption } from '@/types/documents';

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

const version = useForm<{ file: File | null }>({ file: null });
const link = useForm({ entity_type: '', entity_id: '', link_type: '', required_evidence: false });
const share = useForm({ user_id: '', expires_at: '', reason: '' });
const del = useForm({ reason: '' });
const linkTypes = computed(() => props.entityTypes.find((t) => t.type === link.entity_type)?.link_types ?? []);

function archive(restore: boolean) {
    router.post(route('documents.archive', props.document.id), { restore });
}
function hold(on: boolean) {
    const reason = window.prompt(on ? 'Reason for legal hold (audited)' : 'Reason for releasing legal hold (audited)');
    if (reason) router.post(route('documents.legal-hold', props.document.id), { hold: on, reason });
}
function destroy() {
    if (window.confirm('Permanently delete this document? Stored files are removed; this cannot be undone.')) {
        del.delete(route('documents.destroy', props.document.id));
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="document.title" />
        <template #header>
            <h1 class="text-lg font-semibold">
                {{ document.title }}
                <span class="ml-2 rounded bg-surface-100 px-2 py-0.5 text-xs font-normal">{{ document.classification }}</span>
                <span
                    v-if="document.frozen"
                    class="ml-1 rounded bg-surface-200 px-2 py-0.5 text-xs font-normal"
                >frozen</span>
                <span
                    v-if="document.legal_hold"
                    class="ml-1 rounded bg-red-100 px-2 py-0.5 text-xs font-normal text-red-700"
                >legal hold</span>
                <span
                    v-if="document.archived"
                    class="ml-1 rounded bg-surface-200 px-2 py-0.5 text-xs font-normal"
                >archived</span>
            </h1>
        </template>

        <dl class="mb-6 grid max-w-2xl grid-cols-2 gap-2 rounded-lg border border-surface-200 bg-white p-4 text-sm">
            <dt class="text-surface-500">
                Owner
            </dt><dd>{{ document.owner }}</dd>
            <dt class="text-surface-500">
                Current version
            </dt><dd>v{{ document.version }} · {{ document.file_name }}</dd>
            <dt class="text-surface-500">
                Retention
            </dt><dd>{{ document.retention ?? '—' }}<span v-if="document.retention_until"> (until {{ document.retention_until }})</span></dd>
            <dt class="text-surface-500">
                Tags
            </dt><dd>{{ document.tags.join(', ') || '—' }}</dd>
            <dt
                v-if="document.legal_hold"
                class="text-surface-500"
            >
                Legal hold
            </dt><dd v-if="document.legal_hold">
                {{ document.legal_hold_reason }}
            </dd>
        </dl>

        <h2 class="mb-2 font-semibold">
            Versions
        </h2>
        <div class="mb-6 overflow-x-auto rounded-lg border border-surface-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-surface-100 text-left text-xs uppercase text-surface-500">
                    <tr>
                        <th class="px-3 py-2">
                            Ver.
                        </th><th class="px-3 py-2">
                            File
                        </th><th class="px-3 py-2">
                            Size
                        </th><th class="px-3 py-2">
                            SHA-256
                        </th><th class="px-3 py-2">
                            Scan
                        </th><th class="px-3 py-2">
                            Uploaded
                        </th><th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="v in versions"
                        :key="v.id"
                        class="border-t border-surface-100"
                    >
                        <td class="px-3 py-2">
                            v{{ v.version_no }}
                        </td>
                        <td class="px-3 py-2">
                            {{ v.file_name }} <span class="text-xs text-surface-400">{{ v.mime_type }}</span>
                        </td>
                        <td class="px-3 py-2">
                            {{ formatBytes(v.size_bytes) }}
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ v.content_hash.slice(0, 16) }}…
                        </td>
                        <td class="px-3 py-2 text-xs">
                            {{ v.scan_status }}
                        </td>
                        <td class="px-3 py-2 text-xs">
                            {{ v.uploaded_by }} · {{ v.created_at.slice(0, 16).replace('T', ' ') }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <a
                                :href="route('documents.download', { document: document.id, version: v.version_no })"
                                class="text-brand-700 hover:underline"
                            >Download</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <form
            v-if="can.edit && !document.frozen"
            class="mb-8 flex flex-wrap items-end gap-2"
            @submit.prevent="version.post(route('documents.versions.store', document.id), { forceFormData: true, onSuccess: () => version.reset() })"
        >
            <FormField
                id="new-version"
                label="Upload a new version"
                :error="version.errors.file"
            >
                <input
                    id="new-version"
                    type="file"
                    required
                    class="text-sm"
                    @input="version.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                >
            </FormField>
            <button
                type="submit"
                :disabled="version.processing || !version.file"
                class="rounded-md bg-brand-600 px-4 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Upload version
            </button>
        </form>

        <h2 class="mb-2 font-semibold">
            Linked records
        </h2>
        <ul class="mb-3 text-sm">
            <li
                v-for="l in links"
                :key="l.id"
            >
                {{ l.label }} · <span class="text-surface-500">{{ l.link_type }}</span><span
                    v-if="l.required_evidence"
                    class="ml-1 text-xs text-brand-700"
                >required evidence</span>
            </li>
            <li
                v-if="links.length === 0"
                class="text-surface-400"
            >
                Not linked to any record.
            </li>
        </ul>
        <form
            v-if="can.edit"
            class="mb-8 flex flex-wrap gap-2 text-sm"
            @submit.prevent="link.post(route('documents.links.store', document.id), { onSuccess: () => link.reset() })"
        >
            <select
                v-model="link.entity_type"
                required
                aria-label="Entity type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    value=""
                    disabled
                >
                    Record type…
                </option>
                <option
                    v-for="t in entityTypes"
                    :key="t.type"
                    :value="t.type"
                >
                    {{ t.label }}
                </option>
            </select>
            <input
                v-model="link.entity_id"
                required
                placeholder="record id"
                aria-label="Record id"
                inputmode="numeric"
                class="w-28 rounded-md border border-surface-300 px-2 py-2"
            >
            <select
                v-model="link.link_type"
                required
                aria-label="Link type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="lt in linkTypes"
                    :key="lt"
                >
                    {{ lt }}
                </option>
            </select>
            <label class="flex items-center gap-1"><input
                v-model="link.required_evidence"
                type="checkbox"
            > required evidence</label>
            <button
                type="submit"
                class="rounded-md border border-surface-300 px-3 py-2 hover:bg-surface-100"
            >
                Link
            </button>
            <p
                v-if="Object.keys(link.errors).length"
                class="w-full text-red-600"
            >
                {{ Object.values(link.errors).join(' ') }}
            </p>
        </form>

        <template v-if="can.edit">
            <h2 class="mb-2 font-semibold">
                Explicit shares
            </h2>
            <ul class="mb-3 text-sm">
                <li
                    v-for="s in shares"
                    :key="s.id"
                    class="flex items-center gap-2"
                >
                    {{ s.user }} <span class="text-xs text-surface-500">{{ s.expires_at ? 'until ' + s.expires_at.slice(0, 10) : 'no expiry' }}{{ s.active ? '' : ' (expired)' }} · {{ s.reason }}</span>
                    <button
                        class="text-xs text-red-700 hover:underline"
                        @click="router.delete(route('documents.shares.destroy', { document: document.id, share: s.id }))"
                    >
                        remove
                    </button>
                </li>
            </ul>
            <form
                class="mb-8 flex flex-wrap gap-2 text-sm"
                @submit.prevent="share.post(route('documents.shares.store', document.id), { onSuccess: () => share.reset() })"
            >
                <select
                    v-model="share.user_id"
                    required
                    aria-label="User"
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
                    placeholder="reason (audited)"
                    aria-label="Reason"
                    class="rounded-md border border-surface-300 px-2 py-2"
                >
                <button
                    type="submit"
                    class="rounded-md border border-surface-300 px-3 py-2 hover:bg-surface-100"
                >
                    Share
                </button>
                <p
                    v-if="Object.keys(share.errors).length"
                    class="w-full text-red-600"
                >
                    {{ Object.values(share.errors).join(' ') }}
                </p>
            </form>
        </template>

        <div class="flex flex-wrap gap-2 text-sm">
            <button
                v-if="can.edit"
                class="rounded-md border border-surface-300 px-3 py-2 hover:bg-surface-100"
                @click="archive(document.archived)"
            >
                {{ document.archived ? 'Restore from archive' : 'Archive' }}
            </button>
            <button
                v-if="can.legalHold"
                class="rounded-md border border-surface-300 px-3 py-2 hover:bg-surface-100"
                @click="hold(!document.legal_hold)"
            >
                {{ document.legal_hold ? 'Release legal hold' : 'Apply legal hold' }}
            </button>
            <template v-if="can.delete">
                <input
                    v-model="del.reason"
                    placeholder="reason for permanent deletion"
                    aria-label="Deletion reason"
                    class="rounded-md border border-surface-300 px-2 py-2"
                >
                <button
                    class="rounded-md border border-red-300 px-3 py-2 text-red-700 hover:bg-red-50"
                    @click="destroy"
                >
                    Delete permanently
                </button>
                <p
                    v-if="del.errors.reason"
                    class="w-full text-red-600"
                >
                    {{ del.errors.reason }}
                </p>
            </template>
        </div>
    </AppLayout>
</template>
