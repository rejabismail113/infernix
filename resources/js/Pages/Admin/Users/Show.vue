<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface UserRow { id: number; name: string; email: string; roles: string[]; mfa_enabled: boolean; mfa_required: boolean; active: boolean; last_login_at: string | null; access_from: string | null; access_until: string | null }
const props = defineProps<{ user: UserRow; roles: { id: number; code: string; name: string }[]; can: { edit: boolean; delete: boolean } }>();
const rolesForm = useForm({ roles: [...props.user.roles], reason: '', access_from: props.user.access_from?.slice(0, 10) ?? '', access_until: props.user.access_until?.slice(0, 10) ?? '' });
const deactivate = useForm({ reason: '' });
</script>

<template>
    <AppLayout>
        <Head :title="user.name" />
        <template #header>
            <h1 class="text-lg font-semibold">
                {{ user.name }}
            </h1>
        </template>
        <dl class="mb-6 grid max-w-xl grid-cols-2 gap-2 rounded-lg border border-surface-200 bg-white p-4 text-sm">
            <dt class="text-surface-500">
                Email
            </dt><dd>{{ user.email }}</dd>
            <dt class="text-surface-500">
                Roles
            </dt><dd>{{ user.roles.join(', ') || '—' }}</dd>
            <dt class="text-surface-500">
                MFA
            </dt><dd>{{ user.mfa_enabled ? 'enabled' : (user.mfa_required ? 'REQUIRED, not enrolled' : 'off') }}</dd>
            <dt class="text-surface-500">
                Status
            </dt><dd>{{ user.active ? 'active' : 'deactivated' }}</dd>
            <dt class="text-surface-500">
                Last login
            </dt><dd>{{ user.last_login_at ?? '—' }}</dd>
            <dt
                v-if="user.access_until"
                class="text-surface-500"
            >
                Access window
            </dt><dd v-if="user.access_until">
                {{ user.access_from?.slice(0, 10) }} → {{ user.access_until.slice(0, 10) }}
            </dd>
        </dl>

        <section
            v-if="can.edit"
            class="mb-6 max-w-xl rounded-lg border border-surface-200 bg-white p-4"
        >
            <h2 class="mb-3 font-semibold">
                Roles
            </h2>
            <form
                class="space-y-3"
                @submit.prevent="rolesForm.put(route('admin.users.roles', user.id))"
            >
                <label
                    v-for="r in roles"
                    :key="r.code"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="rolesForm.roles"
                        type="checkbox"
                        :value="r.code"
                    > {{ r.code }} — {{ r.name }}
                </label>
                <div
                    v-if="rolesForm.roles.includes('AUD')"
                    class="grid grid-cols-2 gap-2"
                >
                    <FormField
                        id="access_from"
                        label="AUD access from"
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
                        label="AUD access until"
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
                <button
                    type="submit"
                    :disabled="rolesForm.processing"
                    class="rounded-md bg-brand-600 px-4 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
                >
                    Save roles
                </button>
            </form>
        </section>

        <section
            v-if="can.delete"
            class="max-w-xl rounded-lg border border-surface-200 bg-white p-4"
        >
            <h2 class="mb-3 font-semibold">
                {{ user.active ? 'Deactivate' : 'Reactivate' }}
            </h2>
            <form
                class="flex gap-2"
                @submit.prevent="deactivate.post(route(user.active ? 'admin.users.deactivate' : 'admin.users.reactivate', user.id))"
            >
                <input
                    v-model="deactivate.reason"
                    required
                    placeholder="Reason (audited)"
                    aria-label="Reason"
                    class="flex-1 rounded-md border border-surface-300 px-3 py-2"
                >
                <button
                    type="submit"
                    :disabled="deactivate.processing"
                    class="rounded-md border border-red-300 px-4 py-2 text-red-700 hover:bg-red-50 disabled:opacity-50"
                >
                    {{ user.active ? 'Deactivate' : 'Reactivate' }}
                </button>
            </form>
            <p
                v-if="deactivate.errors.reason"
                class="mt-1 text-sm text-red-600"
            >
                {{ deactivate.errors.reason }}
            </p>
        </section>
    </AppLayout>
</template>
