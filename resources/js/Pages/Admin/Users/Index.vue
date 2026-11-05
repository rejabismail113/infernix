<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { TableColumn } from '@/types/table';

interface UserRow { id: number; name: string; email: string; roles: string[]; mfa_enabled: boolean; active: boolean; last_login_at: string | null }
defineProps<{
    users: UserRow[];
    invitations: { id: number; email: string; role: string; expires_at: string }[];
    roles: { id: number; code: string; name: string }[];
    can: { create: boolean };
}>();
const inviteOpen = ref(false);
const invite = useForm({ email: '', name: '', role: 'STF' });
const columns: TableColumn[] = [
    { key: 'name', label: 'Name', sortable: true, hideable: false },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'roles', label: 'Roles' },
    { key: 'mfa_enabled', label: 'MFA', sortable: true },
    { key: 'active', label: 'Status', sortable: true },
    { key: 'last_login_at', label: 'Last login', sortable: true },
];
function send() {
    invite.post(route('admin.users.invite'), { onSuccess: () => { invite.reset(); inviteOpen.value = false; } });
}
</script>

<template>
    <AppLayout
        title="Users"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Users' }]"
    >
        <template #actions>
            <Button
                v-if="can.create"
                @click="inviteOpen = true"
            >
                Invite user
            </Button>
        </template>
        <DataTable
            :columns="columns"
            :rows="users"
            prefs-key="admin.users"
            caption="Users"
        >
            <template #cell-name="{ row }">
                <Link
                    :href="route('admin.users.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.name }}
                </Link>
            </template>
            <template #cell-roles="{ row }">
                <span class="flex flex-wrap gap-1"><StatusChip
                    v-for="r in row.roles"
                    :key="r"
                    :status="r"
                    tone="brand"
                    :label="r"
                /></span>
            </template>
            <template #cell-mfa_enabled="{ row }">
                <StatusChip
                    :status="row.mfa_enabled ? 'active' : 'draft'"
                    :label="row.mfa_enabled ? 'On' : 'Off'"
                />
            </template>
            <template #cell-active="{ row }">
                <StatusChip :status="row.active ? 'active' : 'deactivated'" />
            </template>
            <template #cell-last_login_at="{ row }">
                {{ row.last_login_at ? row.last_login_at.slice(0, 16).replace('T', ' ') : 'never' }}
            </template>
        </DataTable>

        <DataTable
            v-if="invitations.length"
            :columns="[{ key: 'email', label: 'Pending invitation' }, { key: 'role', label: 'Role' }, { key: 'expires_at', label: 'Expires' }]"
            :rows="invitations"
        />

        <Modal
            :open="inviteOpen"
            title="Invite a user"
            size="md"
            @close="inviteOpen = false"
        >
            <form
                id="invite-form"
                class="space-y-3"
                @submit.prevent="send"
            >
                <FormField
                    id="inv-email"
                    label="Email"
                    :error="invite.errors.email"
                >
                    <input
                        id="inv-email"
                        v-model="invite.email"
                        data-autofocus
                        type="email"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="inv-name"
                    label="Name (optional)"
                >
                    <input
                        id="inv-name"
                        v-model="invite.name"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="inv-role"
                    label="Role"
                    :error="invite.errors.role"
                >
                    <select
                        id="inv-role"
                        v-model="invite.role"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="r in roles"
                            :key="r.code"
                            :value="r.code"
                        >
                            {{ r.code }} — {{ r.name }}
                        </option>
                    </select>
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="inviteOpen = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="invite-form"
                    :disabled="invite.processing"
                >
                    Send invitation
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
