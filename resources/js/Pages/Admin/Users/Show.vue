<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface UserRow { id: number; name: string; email: string; roles: string[]; mfa_enabled: boolean; mfa_required: boolean; active: boolean; last_login_at: string | null; access_from: string | null; access_until: string | null }
const props = defineProps<{ user: UserRow; roles: { id: number; code: string; name: string }[]; can: { edit: boolean; delete: boolean } }>();
const rolesForm = useForm({ roles: [...props.user.roles], reason: '', access_from: props.user.access_from?.slice(0, 10) ?? '', access_until: props.user.access_until?.slice(0, 10) ?? '' });
const deactivate = useForm({ reason: '' });
</script>

<template>
    <AppLayout
        :title="user.name"
        :status="user.active ? 'active' : 'deactivated'"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Users', href: route('admin.users.index') }, { label: user.name }]"
    >
        <div class="grid gap-5 xl:grid-cols-3">
            <Card
                title="Profile"
                class="xl:col-span-1"
            >
                <dl class="grid grid-cols-3 gap-y-2 text-sm">
                    <dt class="text-surface-500">
                        Email
                    </dt><dd class="col-span-2">
                        {{ user.email }}
                    </dd>
                    <dt class="text-surface-500">
                        Roles
                    </dt><dd class="col-span-2 flex flex-wrap gap-1">
                        <StatusChip
                            v-for="r in user.roles"
                            :key="r"
                            :status="r"
                            tone="brand"
                            :label="r"
                        />
                    </dd>
                    <dt class="text-surface-500">
                        MFA
                    </dt><dd class="col-span-2">
                        <StatusChip
                            :status="user.mfa_enabled ? 'active' : (user.mfa_required ? 'missing' : 'draft')"
                            :label="user.mfa_enabled ? 'Enabled' : (user.mfa_required ? 'Required — not enrolled' : 'Off')"
                        />
                    </dd>
                    <dt class="text-surface-500">
                        Last login
                    </dt><dd class="col-span-2">
                        {{ user.last_login_at?.slice(0, 16).replace('T', ' ') ?? 'never' }}
                    </dd>
                    <template v-if="user.access_until">
                        <dt class="text-surface-500">
                            Access window
                        </dt><dd class="col-span-2">
                            {{ user.access_from?.slice(0, 10) }} → {{ user.access_until.slice(0, 10) }}
                        </dd>
                    </template>
                </dl>
            </Card>

            <Card
                v-if="can.edit"
                title="Roles"
                description="Changes take effect immediately and are audited."
                class="xl:col-span-2"
            >
                <form
                    class="space-y-3"
                    @submit.prevent="rolesForm.put(route('admin.users.roles', user.id))"
                >
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="r in roles"
                            :key="r.code"
                            class="flex items-center gap-2 rounded-md border border-surface-200 px-3 py-2 text-sm"
                        >
                            <input
                                v-model="rolesForm.roles"
                                type="checkbox"
                                :value="r.code"
                            > <span class="font-medium">{{ r.code }}</span> {{ r.name }}
                        </label>
                    </div>
                    <div
                        v-if="rolesForm.roles.includes('AUD')"
                        class="grid gap-3 sm:grid-cols-2"
                    >
                        <FormField
                            id="access_from"
                            label="Auditor access from"
                        >
                            <input
                                id="access_from"
                                v-model="rolesForm.access_from"
                                type="date"
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                        </FormField>
                        <FormField
                            id="access_until"
                            label="Auditor access until"
                            :error="rolesForm.errors.access_until"
                        >
                            <input
                                id="access_until"
                                v-model="rolesForm.access_until"
                                type="date"
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                        </FormField>
                    </div>
                    <FormField
                        id="reason"
                        label="Reason (audited)"
                        :error="rolesForm.errors.reason"
                    >
                        <input
                            id="reason"
                            v-model="rolesForm.reason"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <Button
                        type="submit"
                        :disabled="rolesForm.processing"
                    >
                        Save roles
                    </Button>
                </form>
            </Card>

            <Card
                v-if="can.delete"
                :title="user.active ? 'Deactivate account' : 'Reactivate account'"
                class="xl:col-span-3"
            >
                <form
                    class="flex flex-wrap gap-2"
                    @submit.prevent="deactivate.post(route(user.active ? 'admin.users.deactivate' : 'admin.users.reactivate', user.id))"
                >
                    <input
                        v-model="deactivate.reason"
                        required
                        placeholder="Reason (audited)"
                        aria-label="Reason"
                        class="min-w-64 flex-1 rounded-md border border-surface-300 px-3 py-2"
                    >
                    <Button
                        type="submit"
                        :variant="user.active ? 'danger' : 'secondary'"
                        :disabled="deactivate.processing"
                    >
                        {{ user.active ? 'Deactivate' : 'Reactivate' }}
                    </Button>
                </form>
                <p
                    v-if="deactivate.errors.reason"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ deactivate.errors.reason }}
                </p>
            </Card>
        </div>
    </AppLayout>
</template>
