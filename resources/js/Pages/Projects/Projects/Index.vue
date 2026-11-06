<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ProjectRow { id: number; code: string; name: string; client: string; engagement_type: string; contract_value: string; status_delivery: string; status_payment: string; source_quotation_id: number | null }
const props = defineProps<{ projects: ProjectRow[]; filters: Record<string, string | null>; clients: { id: number; label: string }[]; leads: { id: number; label: string; client_id: number | null }[]; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
const open = ref(false);
const form = useForm({ client_id: '' as number | '', lead_id: '' as number | '', name: '', engagement_type: 'project', scope: '', starts_on: '', ends_on: '', contract_value: '' as string | number | null, billing_terms: '' });
const save = () => form.transform((d) => ({ ...d, lead_id: d.lead_id || null, contract_value: d.contract_value === '' || d.contract_value === null ? null : String(d.contract_value), starts_on: d.starts_on || null, ends_on: d.ends_on || null })).post(route('projects.projects.store'));
</script>

<template>
    <AppLayout
        title="Projects"
        :breadcrumbs="[{ label: 'Projects' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'projects.contribution')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Contribution by project
            </Link>
            <Button
                v-if="can.create"
                data-test="new-project"
                @click="open = true"
            >
                New project
            </Button>
        </template>
        <form
            class="flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('projects.projects.index'), { q: f.q || undefined, status: f.status || undefined }, { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Code or name"
                aria-label="Search"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.status"
                aria-label="Delivery status"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any delivery status
                </option>
                <option
                    v-for="s in ['lead', 'active', 'on_hold', 'completed', 'closed']"
                    :key="s"
                    :value="s"
                >
                    {{ s.replace('_', ' ') }}
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="[{ key: 'code', label: 'Code', hideable: false }, { key: 'name', label: 'Project' }, { key: 'client', label: 'Client' }, { key: 'engagement_type', label: 'Type' }, { key: 'contract_value', label: 'Contract value', numeric: true }, { key: 'status_delivery', label: 'Delivery' }, { key: 'status_payment', label: 'Billing' }]"
            :rows="projects"
            prefs-key="projects.list"
            empty-text="No projects yet."
        >
            <template #cell-code="{ row }">
                <Link
                    :href="route('projects.projects.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.code }}
                </Link>
            </template>
            <template #cell-contract_value="{ row }">
                <Amount :value="row.contract_value" />
            </template>
            <template #cell-status_delivery="{ row }">
                <StatusChip :status="row.status_delivery" />
            </template>
            <template #cell-status_payment="{ row }">
                <StatusChip
                    :status="row.status_payment === 'paid' ? 'paid' : row.status_payment === 'unbilled' ? 'draft' : 'pending'"
                    :label="row.status_payment.replace('_', ' ')"
                />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="New project"
            size="lg"
            @close="open = false"
        >
            <p class="mb-3 text-xs text-surface-500">
                Projects can be created on their own — invoicing is separate (M02-01). From an accepted quotation, use “Create project” on the quotation instead.
            </p>
            <form
                id="project-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="save"
            >
                <FormField
                    id="p-name"
                    label="Project name"
                    :error="form.errors.name"
                    class="col-span-2"
                >
                    <input
                        id="p-name"
                        v-model="form.name"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="p-client"
                    label="Client"
                    :error="form.errors.client_id"
                >
                    <select
                        id="p-client"
                        v-model="form.client_id"
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option
                            v-for="c in clients"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="p-lead"
                    label="From lead (optional)"
                >
                    <select
                        id="p-lead"
                        v-model="form.lead_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            —
                        </option>
                        <option
                            v-for="l in leads.filter((x) => !form.client_id || x.client_id === form.client_id)"
                            :key="l.id"
                            :value="l.id"
                        >
                            {{ l.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="p-type"
                    label="Engagement type"
                >
                    <select
                        id="p-type"
                        v-model="form.engagement_type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="project">
                            Project
                        </option><option value="training">
                            Training
                        </option><option value="retainer">
                            Retainer
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="p-value"
                    label="Contract value"
                    :error="form.errors.contract_value"
                >
                    <MoneyInput
                        id="p-value"
                        v-model="form.contract_value"
                    />
                </FormField>
                <FormField
                    id="p-start"
                    label="Start"
                >
                    <input
                        id="p-start"
                        v-model="form.starts_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="p-end"
                    label="End"
                    :error="form.errors.ends_on"
                >
                    <input
                        id="p-end"
                        v-model="form.ends_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="p-scope"
                    label="Scope"
                    class="col-span-2"
                >
                    <textarea
                        id="p-scope"
                        v-model="form.scope"
                        rows="4"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    />
                </FormField>
                <FormField
                    id="p-billing"
                    label="Billing terms"
                    class="col-span-2"
                >
                    <input
                        id="p-billing"
                        v-model="form.billing_terms"
                        placeholder="e.g. 50% on signing, 50% on completion"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="project-form"
                    :disabled="form.processing"
                >
                    Create project
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
