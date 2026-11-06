<script setup lang="ts">
/**
 * Approval matrix (M12-03): versions per entity type. A change publishes a new effective-dated version; earlier
 * versions are never edited. Amounts are decimal strings (MoneyInput) — never JS numbers.
 */
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Rule { level: number; min_amount: string; max_amount: string | null; approver_roles: string[] }
interface Version { id: number; version: number; effective_from: string; effective_to: string | null; notes: string | null; current: boolean; rules: Rule[] }
interface Matrix { entity_type: string; versions: Version[] }
const props = defineProps<{ matrices: Matrix[]; roles: string[]; can: { edit: boolean } }>();
const page = usePage<{ errors: Record<string, string> }>();

const selected = ref(props.matrices[0]?.entity_type ?? '');
const matrix = computed(() => props.matrices.find((m) => m.entity_type === selected.value));
const current = computed(() => matrix.value?.versions.find((v) => v.current) ?? matrix.value?.versions[0]);
const label = (t: string) => t.replace(/_/g, ' ');
const today = new Date().toLocaleDateString('en-CA'); // local YYYY-MM-DD (UTC would be a day behind in KL before 08:00)

const form = useForm({ entity_type: '', effective_from: today, reason: '', rules: [] as { level: number; min_amount: string; max_amount: string | null; approver_roles: string[] }[] });
const editing = ref(false);
const startEdit = () => {
    form.entity_type = selected.value;
    form.effective_from = today;
    form.reason = '';
    form.rules = (current.value?.rules ?? []).map((r) => ({ level: r.level, min_amount: r.min_amount, max_amount: r.max_amount, approver_roles: [...r.approver_roles] }));
    editing.value = true;
};
const addRule = () => form.rules.push({ level: 1, min_amount: '0.00', max_amount: null, approver_roles: ['OWN'] });
const toggleRole = (i: number, role: string) => {
    const roles = form.rules[i].approver_roles;
    form.rules[i].approver_roles = roles.includes(role) ? roles.filter((r) => r !== role) : [...roles, role];
};
const publish = () => form.post(route('admin.approval-matrix.store'), { preserveScroll: true, onSuccess: () => { editing.value = false; } });
</script>

<template>
    <AppLayout
        title="Approval matrix"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Approval matrix' }]"
    >
        <div class="grid gap-5 lg:grid-cols-[16rem_1fr]">
            <Card title="Document types">
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="m in matrices"
                        :key="m.entity_type"
                    >
                        <button
                            type="button"
                            class="w-full rounded-md px-3 py-2 text-left capitalize"
                            :class="m.entity_type === selected ? 'bg-brand-50 font-medium text-brand-800' : 'hover:bg-surface-100'"
                            :aria-current="m.entity_type === selected ? 'true' : undefined"
                            @click="selected = m.entity_type; editing = false"
                        >
                            {{ label(m.entity_type) }}
                        </button>
                    </li>
                </ul>
            </Card>

            <div class="space-y-5">
                <Card
                    v-if="!editing"
                    :title="`${label(selected)} — versions`"
                    description="The current version decides who approves. Maker-checker always applies on top: whoever prepared an item can never approve it."
                >
                    <template #actions>
                        <Button
                            v-if="can.edit"
                            data-test="new-version"
                            @click="startEdit"
                        >
                            New version
                        </Button>
                    </template>
                    <div
                        v-for="v in matrix?.versions ?? []"
                        :key="v.id"
                        class="mb-4 rounded-lg border border-surface-200 p-4"
                    >
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold">v{{ v.version }}</span>
                            <StatusChip
                                :status="v.current ? 'approved' : 'draft'"
                                :label="v.current ? 'Current' : (v.effective_from > today ? 'Scheduled' : 'Superseded')"
                            />
                            <span class="text-surface-500">from {{ v.effective_from }}{{ v.effective_to ? ` to ${v.effective_to}` : '' }}</span>
                            <span
                                v-if="v.notes"
                                class="text-surface-500"
                            >· {{ v.notes }}</span>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase text-surface-500">
                                <tr>
                                    <th class="py-1">
                                        Level
                                    </th><th>From (RM)</th><th>To (RM)</th><th>Approver roles</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(r, i) in v.rules"
                                    :key="i"
                                    class="border-t border-surface-100"
                                >
                                    <td class="py-1">
                                        {{ r.level }}
                                    </td>
                                    <td class="font-mono">
                                        {{ r.min_amount }}
                                    </td>
                                    <td class="font-mono">
                                        {{ r.max_amount ?? 'and above' }}
                                    </td>
                                    <td>{{ r.approver_roles.join(', ') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card
                    v-else
                    :title="`New version — ${label(selected)}`"
                    description="Level 1 must cover every amount from 0.00 with the last range open-ended; higher levels add approvals for the ranges they list. Needs re-authentication; the change is audited."
                >
                    <form
                        class="space-y-4"
                        @submit.prevent="publish"
                    >
                        <p
                            v-if="page.props.errors?.general"
                            class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800"
                            role="alert"
                        >
                            {{ page.props.errors.general }}
                        </p>
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase text-surface-500">
                                <tr>
                                    <th>Level</th><th>From (RM)</th><th>To (RM, blank = no limit)</th><th>Approver roles</th><th />
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(r, i) in form.rules"
                                    :key="i"
                                    class="border-t border-surface-100 align-top"
                                >
                                    <td class="py-2 pr-2">
                                        <label
                                            class="sr-only"
                                            :for="`level-${i}`"
                                        >Level</label>
                                        <select
                                            :id="`level-${i}`"
                                            v-model.number="r.level"
                                            class="rounded-md border border-surface-300 px-2 py-2"
                                        >
                                            <option
                                                v-for="n in 5"
                                                :key="n"
                                                :value="n"
                                            >
                                                {{ n }}
                                            </option>
                                        </select>
                                    </td>
                                    <td class="py-2 pr-2">
                                        <MoneyInput
                                            :id="`min-${i}`"
                                            v-model="r.min_amount"
                                        />
                                    </td>
                                    <td class="py-2 pr-2">
                                        <MoneyInput
                                            :id="`max-${i}`"
                                            :model-value="r.max_amount"
                                            placeholder="no limit"
                                            @update:model-value="(v) => (r.max_amount = v === '' || v === null ? null : String(v))"
                                        />
                                    </td>
                                    <td class="py-2 pr-2">
                                        <div class="flex flex-wrap gap-2">
                                            <label
                                                v-for="role in roles"
                                                :key="role"
                                                class="inline-flex items-center gap-1"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :checked="r.approver_roles.includes(role)"
                                                    @change="toggleRole(i, role)"
                                                >
                                                {{ role }}
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-2">
                                        <button
                                            type="button"
                                            class="text-xs text-red-700 hover:underline"
                                            @click="form.rules.splice(i, 1)"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <Button
                            type="button"
                            variant="secondary"
                            @click="addRule"
                        >
                            Add rule
                        </Button>
                        <div class="grid gap-4 md:grid-cols-3">
                            <FormField
                                id="effective_from"
                                label="Effective from"
                                :error="form.errors.effective_from"
                            >
                                <input
                                    id="effective_from"
                                    v-model="form.effective_from"
                                    type="date"
                                    :min="today"
                                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                                >
                            </FormField>
                            <FormField
                                id="reason"
                                label="Reason (audited)"
                                :error="form.errors.reason"
                                class="md:col-span-2"
                            >
                                <input
                                    id="reason"
                                    v-model="form.reason"
                                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                                >
                            </FormField>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                :disabled="form.processing"
                                data-test="publish"
                            >
                                Publish version
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                @click="editing = false"
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
