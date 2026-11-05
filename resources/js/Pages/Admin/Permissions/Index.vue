<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Perm { id: number; role_id: number; resource: string; action: string; record_scope: string; policy_version: number }
const props = defineProps<{ roles: { id: number; code: string; name: string }[]; permissions: Perm[]; actions: string[]; scopes: string[] }>();
const resources = computed(() => [...new Set(props.permissions.map((p) => p.resource))].sort());
const cell = (roleId: number, resource: string, action: string) => props.permissions.find((p) => p.role_id === roleId && p.resource === resource && p.action === action)?.record_scope ?? 'none';
const form = useForm({ role_id: props.roles[0]?.id ?? 0, resource: '', action: 'view', record_scope: 'all', reason: '' });
</script>

<template>
    <AppLayout>
        <Head title="Permissions" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Permission matrix
            </h1>
        </template>
        <div class="overflow-x-auto rounded-lg border border-surface-200 bg-white">
            <table class="min-w-full text-xs">
                <thead class="bg-surface-100">
                    <tr>
                        <th class="px-2 py-2 text-left">
                            Resource · action
                        </th>
                        <th
                            v-for="r in roles"
                            :key="r.id"
                            class="px-2 py-2"
                        >
                            {{ r.code }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template
                        v-for="res in resources"
                        :key="res"
                    >
                        <tr
                            v-for="a in actions"
                            :key="res + a"
                            class="border-t border-surface-100"
                        >
                            <td class="px-2 py-1 font-mono">
                                {{ res }}.{{ a }}
                            </td>
                            <td
                                v-for="r in roles"
                                :key="r.id"
                                class="px-2 py-1 text-center"
                                :class="cell(r.id, res, a) === 'none' ? 'text-surface-300' : 'font-medium text-brand-700'"
                            >
                                {{ cell(r.id, res, a) }}
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <form
            class="mt-6 grid max-w-3xl grid-cols-2 gap-3 rounded-lg border border-surface-200 bg-white p-4 text-sm md:grid-cols-6"
            @submit.prevent="form.put(route('admin.permissions.update'), { onSuccess: () => form.reset('reason') })"
        >
            <select
                v-model="form.role_id"
                aria-label="Role"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="r in roles"
                    :key="r.id"
                    :value="r.id"
                >
                    {{ r.code }}
                </option>
            </select>
            <input
                v-model="form.resource"
                required
                placeholder="resource"
                aria-label="Resource"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <select
                v-model="form.action"
                aria-label="Action"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="a in actions"
                    :key="a"
                >
                    {{ a }}
                </option>
            </select>
            <select
                v-model="form.record_scope"
                aria-label="Scope"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="s in scopes"
                    :key="s"
                >
                    {{ s }}
                </option>
            </select>
            <input
                v-model="form.reason"
                required
                placeholder="reason (audited)"
                aria-label="Reason"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-brand-600 px-3 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Set
            </button>
            <p
                v-if="Object.keys(form.errors).length"
                class="col-span-full text-red-600"
            >
                {{ Object.values(form.errors).join(' ') }}
            </p>
        </form>
    </AppLayout>
</template>
