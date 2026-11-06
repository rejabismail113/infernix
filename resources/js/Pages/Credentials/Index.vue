<script setup lang="ts">
/** Credential register (M13): metadata and vault references only — no secret ever reaches this page. */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, enumOptions, setField } from '@/lib/forms';
import type { FieldDef, Option } from '@/types/operations';
import type { TableColumn } from '@/types/table';

interface Row { id: number; credential_no: string; system_name: string; category: string; environment: string; sensitivity: string; status: string; owner: string | null; vault_provider: string; has_reference: boolean; rotation_due: string | null; expiry_date: string | null; is_shared_service_account: boolean; entitled: boolean }
interface Req { id: number; credential_id: number; credential: string; requester: string | null; justification: string; access_until: string | null; status: string; created_at: string | null }
const props = defineProps<{ credentials: Row[]; categories: string[]; filters: Record<string, string | null>; vault: { configured: boolean; provider: string }; projects: Option[]; clients: Option[]; users: Option[]; pendingRequests: Req[]; can: { create: boolean; audit: boolean; jml: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', category: props.filters.category ?? '', status: props.filters.status ?? '' });
const columns: TableColumn[] = [{ key: 'credential_no', label: 'No', sortable: true }, { key: 'system_name', label: 'System', sortable: true }, { key: 'category', label: 'Category', sortable: true },
    { key: 'environment', label: 'Env' }, { key: 'sensitivity', label: 'Sensitivity', sortable: true }, { key: 'owner', label: 'Owner' }, { key: 'rotation_due', label: 'Rotation due', sortable: true }, { key: 'status', label: 'Status' }];
const open = ref(false);
const form = useForm({ system_name: '', category: 'cloud', environment: 'production', url: '', username_reference: '', owner_id: '' as number | '', project_id: '' as number | '', client_id: '' as number | '', vault_provider: 'bitwarden', vault_item_reference: '', sensitivity: 'high', rotation_interval_days: '90', last_rotated_on: '', expiry_date: '', is_shared_service_account: false, shared_justification: '', notes: '' });
const fields = computed<FieldDef[]>(() => [
    { key: 'system_name', label: 'System / service', type: 'text', required: true, span: 2 }, { key: 'category', label: 'Category', type: 'select', options: enumOptions(props.categories), required: true },
    { key: 'environment', label: 'Environment', type: 'select', options: enumOptions(['production', 'staging', 'development', 'shared']) }, { key: 'url', label: 'Login URL', type: 'url' },
    { key: 'username_reference', label: 'Username / account reference (not the password)', type: 'text' }, { key: 'owner_id', label: 'Resource owner', type: 'select', options: props.users.map((u) => ({ value: u.id, label: u.label })), required: true },
    { key: 'sensitivity', label: 'Sensitivity', type: 'select', options: enumOptions(['low', 'medium', 'high', 'critical']) }, { key: 'project_id', label: 'Project', type: 'select', options: props.projects.map((p) => ({ value: p.id, label: p.label })) },
    { key: 'client_id', label: 'Client (required for client credentials)', type: 'select', options: props.clients.map((c) => ({ value: c.id, label: c.label })) },
    { key: 'vault_provider', label: 'Vault', type: 'select', options: enumOptions(['bitwarden', 'onepassword', 'other', 'none']) }, { key: 'vault_item_reference', label: 'Vault item reference', type: 'text', help: 'The id of the item in the vault — never the secret itself.' },
    { key: 'rotation_interval_days', label: 'Rotate every (days)', type: 'number' }, { key: 'last_rotated_on', label: 'Last rotated', type: 'date' }, { key: 'expiry_date', label: 'Expires', type: 'date' },
    { key: 'is_shared_service_account', label: 'Shared service account (discouraged)', type: 'checkbox' }, { key: 'shared_justification', label: 'Why is a shared account needed?', type: 'textarea' }, { key: 'notes', label: 'Notes (no secrets)', type: 'textarea' },
]);
const save = () => form.transform((d) => blanksToNull(d, ['project_id', 'client_id', 'rotation_interval_days', 'last_rotated_on', 'expiry_date', 'url'])).post(route('credentials.credentials.store'));
const decide = (r: Req, approve: boolean) => { const note = window.prompt(approve ? 'Note (optional)' : 'Why reject?'); if (!approve && !note) return; router.post(route('credentials.requests.decide', r.id), { approve, note }, { preserveScroll: true }); };
</script>

<template>
    <AppLayout
        title="Credentials"
        :breadcrumbs="[{ label: 'Security' }, { label: 'Credentials' }]"
    >
        <template #actions>
            <Link
                v-if="can.audit"
                :href="route('credentials.audit')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Access audit
            </Link>
            <Link
                v-if="can.jml"
                :href="route('credentials.jml.index')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Joiner / mover / leaver
            </Link>
            <Button
                v-if="can.create"
                data-test="add-credential"
                @click="open = true"
            >
                Register credential
            </Button>
        </template>
        <p
            class="mb-3 rounded-md p-3 text-sm"
            :class="vault.configured ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'"
        >
            {{ vault.configured ? `Vault connected (${vault.provider}). Secrets are revealed from the vault only, after re-authentication, and every reveal is audited.` : 'No vault connected: Infernix One shows each credential\'s vault reference — open the secret in your vault.' }}
            Infernix One never stores passwords, keys or tokens.
        </p>

        <Card
            v-if="pendingRequests.length"
            title="Access requests waiting for you"
            class="mb-4"
        >
            <ul class="divide-y divide-surface-100 text-sm">
                <li
                    v-for="r in pendingRequests"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-2 py-2"
                >
                    <span class="font-medium">{{ r.requester }}</span> → <Link
                        :href="route('credentials.credentials.show', r.credential_id)"
                        class="text-brand-700 hover:underline"
                    >
                        {{ r.credential }}
                    </Link>
                    <span class="text-surface-500">{{ r.justification }} {{ r.access_until ? `(until ${r.access_until})` : '' }}</span>
                    <span class="ml-auto flex gap-2">
                        <Button @click="decide(r, true)">Approve</Button>
                        <Button
                            variant="danger"
                            @click="decide(r, false)"
                        >Reject</Button>
                    </span>
                </li>
            </ul>
        </Card>

        <form
            class="mb-3 flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('credentials.credentials.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="System or number"
                aria-label="Search credentials"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.category"
                aria-label="Category"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any category
                </option>
                <option
                    v-for="c in categories"
                    :key="c"
                    :value="c"
                >
                    {{ c }}
                </option>
            </select>
            <Button
                type="submit"
                variant="secondary"
            >
                Filter
            </Button>
        </form>
        <DataTable
            :columns="columns"
            :rows="credentials"
            prefs-key="credentials"
            empty-text="No credentials you can see."
        >
            <template #cell-credential_no="{ row }">
                <Link
                    :href="route('credentials.credentials.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.credential_no }}
                </Link>
            </template>
            <template #cell-system_name="{ row }">
                {{ row.system_name }} <span
                    v-if="row.is_shared_service_account"
                    class="ml-1 rounded bg-amber-50 px-1 text-xs text-amber-800"
                >shared</span>
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>

        <Modal
            :open="open"
            title="Register credential (metadata only)"
            size="xl"
            @close="open = false"
        >
            <form
                id="cred-form"
                class="space-y-2"
                @submit.prevent="save"
            >
                <p class="rounded-md bg-red-50 p-2 text-sm text-red-900">
                    Do not type a password, API key or token anywhere on this form. Store it in the vault and enter the vault item reference.
                </p>
                <SchemaFields
                    :fields="fields"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="cr"
                    @set="(k, v) => setField(form, k, v)"
                />
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="cred-form"
                    :disabled="form.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
