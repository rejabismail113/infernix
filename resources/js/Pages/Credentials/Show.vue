<script setup lang="ts">
/**
 * Credential (M13). Reveal = re-authentication + matrix right + vault entitlement, audited server-side; the value
 * is held in memory for a few seconds, never written to storage, logs or the URL, then wiped (M13-03).
 */
import { router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, enumOptions, setField } from '@/lib/forms';
import type { Option } from '@/types/operations';

interface Credential { id: number; credential_no: string; system_name: string; category: string; environment: string; sensitivity: string; status: string; owner: string | null; vault_provider: string; has_reference: boolean; rotation_due: string | null; expiry_date: string | null; is_shared_service_account: boolean; entitled: boolean; url: string | null; username_reference: string | null; vault_item_reference: string | null; deep_link: string | null; rotation_interval_days: number | null; last_rotated_on: string | null; shared_justification: string | null; notes: string | null; owner_id: number; project_id: number | null; client_id: number | null; project: string | null; client: string | null }
const props = defineProps<{
    credential: Credential; vault: { configured: boolean; provider: string; reveal_seconds: number };
    entitlements: { id: number; user: string | null; granted_at: string; expires_on: string | null; revoked_at: string | null; revoke_reason: string | null; vault_sync: string }[];
    requests: { id: number; requester: string | null; justification: string; access_until: string | null; status: string; created_at: string | null; decision_note: string | null }[];
    myRequest: { status: string; created_at: string } | null; rotations: { rotated_on: string; by: string | null; note: string | null }[];
    projects: Option[]; clients: Option[]; users: Option[]; categories: string[];
    can: { edit: boolean; reveal: boolean; request: boolean; decide: boolean };
}>();
const c = props.credential;
const secret = ref<string | null>(null);
const remaining = ref(0);
const revealError = ref<string | null>(null);
let timer: ReturnType<typeof setInterval> | undefined;
const wipe = () => { secret.value = null; remaining.value = 0; if (timer) clearInterval(timer); };
onBeforeUnmount(wipe);
async function reveal() {
    revealError.value = null;
    const reason = window.prompt('Why do you need this secret? (audited)');
    if (!reason) return;
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
    const res = await fetch(route('credentials.credentials.reveal', c.id), { method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ reason }) });
    if (res.redirected || res.url.includes('/reauth')) { window.location.href = `/reauth?intended=${encodeURIComponent(window.location.href)}`; return; }
    const body = (await res.json().catch(() => ({}))) as { value?: string; expires_in?: number; message?: string };
    if (!res.ok || body.value === undefined) { revealError.value = body.message ?? (res.status === 403 ? 'You are not entitled to reveal this secret.' : 'The secret could not be revealed.'); return; }
    secret.value = body.value;
    remaining.value = body.expires_in ?? props.vault.reveal_seconds;
    timer = setInterval(() => { remaining.value -= 1; if (remaining.value <= 0) wipe(); }, 1000);
}
const copy = async () => { if (secret.value) await navigator.clipboard.writeText(secret.value); };

const editOpen = ref(false);
const form = useForm({ system_name: c.system_name, category: c.category, environment: c.environment, url: c.url ?? '', username_reference: c.username_reference ?? '', owner_id: c.owner_id, project_id: (c.project_id ?? '') as number | '', client_id: (c.client_id ?? '') as number | '',
    vault_provider: c.vault_provider, vault_item_reference: c.vault_item_reference ?? '', sensitivity: c.sensitivity, rotation_interval_days: c.rotation_interval_days === null ? '' : String(c.rotation_interval_days), last_rotated_on: c.last_rotated_on ?? '', expiry_date: c.expiry_date ?? '',
    is_shared_service_account: c.is_shared_service_account, shared_justification: c.shared_justification ?? '', notes: c.notes ?? '' });
const save = () => form.transform((d) => blanksToNull(d, ['project_id', 'client_id', 'rotation_interval_days', 'last_rotated_on', 'expiry_date', 'url'])).put(route('credentials.credentials.update', c.id), { onSuccess: () => { editOpen.value = false; } });
const rot = useForm({ rotated_on: new Date().toISOString().slice(0, 10), note: '' });
const rotate = () => rot.post(route('credentials.credentials.rotate', c.id), { preserveScroll: true, onSuccess: () => rot.reset('note') });
const req = useForm({ justification: '', access_until: '' });
const requestAccess = () => req.transform((d) => blanksToNull(d, ['access_until'])).post(route('credentials.credentials.request', c.id), { preserveScroll: true });
const decide = (id: number, approve: boolean) => { const note = window.prompt(approve ? 'Note (optional)' : 'Why reject?'); if (!approve && !note) return; router.post(route('credentials.requests.decide', id), { approve, note }, { preserveScroll: true }); };
const revoke = (id: number) => { const reason = window.prompt('Why revoke this access?'); if (reason) router.post(route('credentials.entitlements.revoke', id), { reason }, { preserveScroll: true }); };
const setStatus = (status: string) => { const reason = window.prompt(`Reason for marking ${status}?`); router.post(route('credentials.credentials.status', c.id), { status, reason }, { preserveScroll: true }); };
</script>

<template>
    <AppLayout
        :title="`${credential.credential_no} · ${credential.system_name}`"
        :breadcrumbs="[{ label: 'Security' }, { label: 'Credentials', href: route('credentials.credentials.index') }, { label: credential.credential_no }]"
        :status="credential.status"
    >
        <template #actions>
            <Button
                v-if="can.edit"
                variant="secondary"
                @click="editOpen = true"
            >
                Edit metadata
            </Button>
            <Button
                v-if="can.edit && credential.status === 'active'"
                variant="danger"
                @click="setStatus('revoked')"
            >
                Mark revoked
            </Button>
        </template>
        <div class="grid gap-4 lg:grid-cols-3">
            <Card
                title="Metadata"
                class="lg:col-span-2"
            >
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-surface-500">
                            Category
                        </dt><dd>{{ credential.category }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Environment
                        </dt><dd>{{ credential.environment }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Sensitivity
                        </dt><dd>{{ credential.sensitivity }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Owner
                        </dt><dd>{{ credential.owner }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Project / client
                        </dt><dd>{{ credential.project ?? '—' }} / {{ credential.client ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Username ref.
                        </dt><dd>{{ credential.username_reference ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Login URL
                        </dt><dd class="truncate">
                            {{ credential.url ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Rotation due
                        </dt><dd>{{ credential.rotation_due ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-surface-500">
                            Expires
                        </dt><dd>{{ credential.expiry_date ?? '—' }}</dd>
                    </div>
                </dl>
                <p
                    v-if="credential.is_shared_service_account"
                    class="mt-3 rounded-md bg-amber-50 p-2 text-sm text-amber-900"
                >
                    Shared service account (policy warning): {{ credential.shared_justification }}
                </p>
                <p
                    v-if="credential.notes"
                    class="mt-3 text-sm"
                >
                    {{ credential.notes }}
                </p>
            </Card>
            <Card title="Secret">
                <p class="text-sm">
                    Vault: <strong>{{ credential.vault_provider }}</strong><br>
                    Reference: <code class="rounded bg-surface-100 px-1">{{ credential.vault_item_reference ?? '—' }}</code>
                </p>
                <a
                    v-if="credential.deep_link"
                    :href="credential.deep_link"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-1 inline-block text-sm text-brand-700 hover:underline"
                >Open in vault</a>
                <div
                    v-if="can.reveal && vault.configured"
                    class="mt-3"
                >
                    <div
                        v-if="secret !== null"
                        class="space-y-2"
                    >
                        <code
                            class="block select-all break-all rounded bg-surface-900 p-2 font-mono text-sm text-white"
                            data-test="revealed-secret"
                        >{{ secret }}</code>
                        <div class="flex items-center gap-2 text-xs text-surface-500">
                            Hidden in {{ remaining }}s
                            <Button
                                variant="secondary"
                                @click="copy"
                            >
                                Copy
                            </Button>
                            <Button
                                variant="secondary"
                                @click="wipe"
                            >
                                Hide now
                            </Button>
                        </div>
                    </div>
                    <Button
                        v-else
                        data-test="reveal-secret"
                        @click="reveal"
                    >
                        Reveal (re-auth, audited)
                    </Button>
                </div>
                <p
                    v-else-if="!vault.configured"
                    class="mt-3 text-xs text-surface-500"
                >
                    No vault is connected: open the item in your vault using the reference.
                </p>
                <p
                    v-else
                    class="mt-3 text-xs text-surface-500"
                >
                    You do not hold an entitlement for this credential.
                </p>
                <p
                    v-if="revealError"
                    class="mt-2 text-sm text-red-700"
                    role="alert"
                >
                    {{ revealError }}
                </p>
                <form
                    v-if="can.request"
                    class="mt-4 space-y-2"
                    @submit.prevent="requestAccess"
                >
                    <p
                        v-if="myRequest"
                        class="text-xs text-surface-500"
                    >
                        Your last request: {{ myRequest.status }}
                    </p>
                    <textarea
                        v-model="req.justification"
                        rows="2"
                        placeholder="Why do you need access?"
                        aria-label="Justification"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="req.access_until"
                        type="date"
                        aria-label="Access until"
                        class="rounded-md border border-surface-300 px-2 py-1 text-sm"
                    >
                    <p
                        v-if="fieldError(req.errors, 'general') || fieldError(req.errors, 'justification')"
                        class="text-sm text-red-700"
                        role="alert"
                    >
                        {{ fieldError(req.errors, 'general') ?? fieldError(req.errors, 'justification') }}
                    </p>
                    <Button
                        type="submit"
                        variant="secondary"
                        data-test="request-access"
                    >
                        Request access
                    </Button>
                </form>
            </Card>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <Card
                v-if="can.decide || can.edit"
                title="Who has access"
            >
                <ul class="divide-y divide-surface-100 text-sm">
                    <li
                        v-for="e in entitlements"
                        :key="e.id"
                        class="flex flex-wrap items-center gap-2 py-1.5"
                    >
                        <span class="font-medium">{{ e.user }}</span>
                        <span class="text-surface-500">since {{ e.granted_at.slice(0, 10) }} {{ e.expires_on ? `until ${e.expires_on}` : '' }}</span>
                        <StatusChip
                            :status="e.revoked_at ? 'void' : 'active'"
                            :label="e.revoked_at ? `revoked: ${e.revoke_reason}` : 'active'"
                        />
                        <button
                            v-if="!e.revoked_at && can.decide"
                            type="button"
                            class="ml-auto text-xs text-red-700 hover:underline"
                            @click="revoke(e.id)"
                        >
                            Revoke
                        </button>
                    </li>
                    <li
                        v-if="!entitlements.length"
                        class="py-2 text-surface-500"
                    >
                        Only the owner.
                    </li>
                </ul>
                <div
                    v-if="requests.some((r) => r.status === 'pending') && can.decide"
                    class="mt-4"
                >
                    <h3 class="text-sm font-medium">
                        Pending requests
                    </h3>
                    <ul class="mt-1 space-y-1 text-sm">
                        <li
                            v-for="r in requests.filter((x) => x.status === 'pending')"
                            :key="r.id"
                            class="flex flex-wrap items-center gap-2"
                        >
                            {{ r.requester }} — {{ r.justification }}
                            <Button @click="decide(r.id, true)">
                                Approve
                            </Button>
                            <Button
                                variant="danger"
                                @click="decide(r.id, false)"
                            >
                                Reject
                            </Button>
                        </li>
                    </ul>
                </div>
            </Card>
            <Card title="Rotation">
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="(r, i) in rotations"
                        :key="i"
                    >
                        {{ r.rotated_on }} · {{ r.by }} {{ r.note ? `· ${r.note}` : '' }}
                    </li>
                    <li
                        v-if="!rotations.length"
                        class="text-surface-500"
                    >
                        Never recorded.
                    </li>
                </ul>
                <form
                    v-if="can.edit"
                    class="mt-3 flex flex-wrap gap-2"
                    @submit.prevent="rotate"
                >
                    <input
                        v-model="rot.rotated_on"
                        type="date"
                        aria-label="Rotated on"
                        class="rounded-md border border-surface-300 px-2 py-1 text-sm"
                    >
                    <input
                        v-model="rot.note"
                        placeholder="Note (no secrets)"
                        aria-label="Rotation note"
                        class="flex-1 rounded-md border border-surface-300 px-2 py-1 text-sm"
                    >
                    <Button
                        type="submit"
                        variant="secondary"
                    >
                        Record rotation
                    </Button>
                </form>
                <p
                    v-if="fieldError(rot.errors, 'general')"
                    class="mt-1 text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(rot.errors, 'general') }}
                </p>
            </Card>
        </div>

        <Modal
            :open="editOpen"
            title="Edit credential metadata"
            size="xl"
            @close="editOpen = false"
        >
            <form
                id="cred-edit"
                class="space-y-2"
                @submit.prevent="save"
            >
                <SchemaFields
                    :fields="[{ key: 'system_name', label: 'System', type: 'text', span: 2 }, { key: 'category', label: 'Category', type: 'select', options: enumOptions(categories) }, { key: 'environment', label: 'Environment', type: 'select', options: enumOptions(['production', 'staging', 'development', 'shared']) },
                              { key: 'url', label: 'Login URL', type: 'url' }, { key: 'username_reference', label: 'Username reference', type: 'text' }, { key: 'owner_id', label: 'Owner', type: 'select', options: users.map((u) => ({ value: u.id, label: u.label })) },
                              { key: 'sensitivity', label: 'Sensitivity', type: 'select', options: enumOptions(['low', 'medium', 'high', 'critical']) }, { key: 'project_id', label: 'Project', type: 'select', options: projects.map((p) => ({ value: p.id, label: p.label })) },
                              { key: 'client_id', label: 'Client', type: 'select', options: clients.map((x) => ({ value: x.id, label: x.label })) }, { key: 'vault_provider', label: 'Vault', type: 'select', options: enumOptions(['bitwarden', 'onepassword', 'other', 'none']) },
                              { key: 'vault_item_reference', label: 'Vault item reference', type: 'text' }, { key: 'rotation_interval_days', label: 'Rotate every (days)', type: 'number' }, { key: 'expiry_date', label: 'Expires', type: 'date' },
                              { key: 'is_shared_service_account', label: 'Shared service account', type: 'checkbox' }, { key: 'shared_justification', label: 'Justification', type: 'textarea' }, { key: 'notes', label: 'Notes (no secrets)', type: 'textarea' }]"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="ce"
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
                    form="cred-edit"
                    :disabled="form.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
