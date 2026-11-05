<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Perm { id: number; role_id: number; resource: string; action: string; record_scope: string; policy_version: number }
const props = defineProps<{ roles: { id: number; code: string; name: string }[]; permissions: Perm[]; actions: string[]; scopes: string[] }>();
const filter = ref('');
const resources = computed(() => [...new Set(props.permissions.map((p) => p.resource))].sort().filter((r) => r.includes(filter.value.trim())));
const cell = (roleId: number, resource: string, action: string) => props.permissions.find((p) => p.role_id === roleId && p.resource === resource && p.action === action)?.record_scope ?? 'none';
const SCOPE_CLASS: Record<string, string> = { all: 'bg-emerald-50 text-emerald-800', assigned: 'bg-sky-50 text-sky-800', own: 'bg-amber-50 text-amber-800', none: 'text-surface-300' };
const form = useForm({ role_id: props.roles[0]?.id ?? 0, resource: '', action: 'view', record_scope: 'all', reason: '' });
</script>

<template>
    <AppLayout
        title="Permission matrix"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Permissions' }]"
    >
        <Card
            title="Role × resource × action"
            description="Cells show the record scope: all, assigned, own or none. Anything not granted is denied."
        >
            <template #actions>
                <input
                    v-model="filter"
                    placeholder="Filter resources"
                    aria-label="Filter resources"
                    class="rounded-md border border-surface-300 px-3 py-1.5 text-sm"
                >
            </template>
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <caption class="sr-only">
                        Permission matrix
                    </caption>
                    <thead class="text-left text-surface-600">
                        <tr class="border-b border-surface-200">
                            <th
                                scope="col"
                                class="px-2 py-2"
                            >
                                Resource · action
                            </th>
                            <th
                                v-for="r in roles"
                                :key="r.id"
                                scope="col"
                                class="px-2 py-2 text-center"
                                :title="r.name"
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
                                v-for="(a, i) in actions"
                                :key="res + a"
                                :class="i === 0 ? 'border-t-2 border-surface-200' : 'border-t border-surface-50'"
                            >
                                <th
                                    scope="row"
                                    class="whitespace-nowrap px-2 py-1 text-left font-mono font-normal"
                                >
                                    <span :class="i === 0 ? 'font-semibold text-surface-900' : 'text-surface-400'">{{ i === 0 ? res : '' }}</span> <span class="text-surface-600">.{{ a }}</span>
                                </th>
                                <td
                                    v-for="r in roles"
                                    :key="r.id"
                                    class="px-1 py-0.5 text-center"
                                >
                                    <span
                                        class="inline-block min-w-14 rounded px-1.5 py-0.5"
                                        :class="SCOPE_CLASS[cell(r.id, res, a)]"
                                    >{{ cell(r.id, res, a) }}</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card
            title="Change a permission"
            description="Creates a new policy version and records the reason in the audit trail. Requires re-authentication."
        >
            <form
                class="grid grid-cols-2 gap-3 text-sm md:grid-cols-6"
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
                <Button
                    type="submit"
                    :disabled="form.processing"
                >
                    Save
                </Button>
                <p
                    v-if="Object.keys(form.errors).length"
                    class="col-span-full text-red-600"
                    role="alert"
                >
                    {{ Object.values(form.errors).join(' ') }}
                </p>
            </form>
        </Card>
    </AppLayout>
</template>
