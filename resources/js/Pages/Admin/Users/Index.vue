<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface UserRow { id: number; name: string; email: string; roles: string[]; mfa_enabled: boolean; active: boolean; last_login_at: string | null }
defineProps<{
    users: UserRow[];
    invitations: { id: number; email: string; role: string; expires_at: string }[];
    roles: { id: number; code: string; name: string }[];
    can: { create: boolean };
}>();
const invite = useForm({ email: '', name: '', role: 'STF' });
const columns = [
    { key: 'name', label: 'Name' }, { key: 'email', label: 'Email' }, { key: 'roles', label: 'Roles' },
    { key: 'mfa_enabled', label: 'MFA' }, { key: 'active', label: 'Active' }, { key: 'last_login_at', label: 'Last login' },
];
</script>

<template>
    <AppLayout>
        <Head title="Users" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Users
            </h1>
        </template>
        <SimpleTable
            :columns="columns"
            :rows="users"
        >
            <template #cell-mfa_enabled="{ row }">
                {{ row.mfa_enabled ? 'on' : 'off' }}
            </template>
            <template #cell-active="{ row }">
                {{ row.active ? 'yes' : 'no' }}
            </template>
            <template #actions="{ row }">
                <Link
                    :href="route('admin.users.show', row.id as number)"
                    class="text-brand-700 hover:underline"
                >
                    Open
                </Link>
            </template>
        </SimpleTable>

        <section
            v-if="can.create"
            class="mt-8 max-w-lg rounded-lg border border-surface-200 bg-white p-4"
        >
            <h2 class="mb-3 font-semibold">
                Invite a user
            </h2>
            <form
                class="space-y-3"
                @submit.prevent="invite.post(route('admin.users.invite'), { onSuccess: () => invite.reset() })"
            >
                <FormField
                    id="inv-email"
                    label="Email"
                    :error="invite.errors.email"
                >
                    <input
                        id="inv-email"
                        v-model="invite.email"
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
                <button
                    type="submit"
                    :disabled="invite.processing"
                    class="rounded-md bg-brand-600 px-4 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
                >
                    Send invitation
                </button>
            </form>
            <ul
                v-if="invitations.length"
                class="mt-4 text-sm text-surface-600"
            >
                <li
                    v-for="i in invitations"
                    :key="i.id"
                >
                    Pending: {{ i.email }} ({{ i.role }}) · expires {{ i.expires_at.slice(0, 10) }}
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
