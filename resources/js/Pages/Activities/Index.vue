<script setup lang="ts">
/** Corporate activities & travel register (M07-01). Costs are linked from Finance on the activity page. */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { blanksToNull, enumOptions, setField } from '@/lib/forms';
import type { FieldDef, Option } from '@/types/operations';
import type { TableColumn } from '@/types/table';

interface Row { id: number; activity_no: string; type: string; title: string; start_date: string; end_date: string; location: string | null; status: string; budget: string; attendees: number }
const props = defineProps<{ activities: Row[]; types: string[]; filters: Record<string, string | null>; projects: Option[]; clients: Option[]; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', type: props.filters.type ?? '', status: props.filters.status ?? '' });
const columns: TableColumn[] = [
    { key: 'activity_no', label: 'No', sortable: true }, { key: 'type', label: 'Type', sortable: true }, { key: 'title', label: 'Title', sortable: true },
    { key: 'start_date', label: 'Start', sortable: true }, { key: 'end_date', label: 'End' }, { key: 'location', label: 'Location' },
    { key: 'attendees', label: 'Attendees', align: 'right' }, { key: 'budget', label: 'Budget', numeric: true }, { key: 'status', label: 'Status', sortable: true },
];
const open = ref(false);
const today = new Date().toISOString().slice(0, 10);
const form = useForm({ type: 'trip', title: '', start_date: today, end_date: today, location: '', purpose: '', project_id: '' as number | '', client_id: '' as number | '', budget: '' });
const fields = computed<FieldDef[]>(() => [
    { key: 'type', label: 'Type', type: 'select', options: enumOptions(props.types.filter((t) => t !== 'csr')), required: true }, { key: 'title', label: 'Title', type: 'text', required: true },
    { key: 'start_date', label: 'Start', type: 'date', required: true }, { key: 'end_date', label: 'End', type: 'date', required: true }, { key: 'location', label: 'Location', type: 'text' },
    { key: 'budget', label: 'Budget (RM)', type: 'money' }, { key: 'project_id', label: 'Project', type: 'select', options: props.projects.map((p) => ({ value: p.id, label: p.label })) },
    { key: 'client_id', label: 'Client', type: 'select', options: props.clients.map((c) => ({ value: c.id, label: c.label })) }, { key: 'purpose', label: 'Purpose', type: 'textarea' },
]);
const save = () => form.transform((d) => blanksToNull(d, ['project_id', 'client_id', 'budget'])).post(route('activities.activities.store'));
</script>

<template>
    <AppLayout
        title="Activities & travel"
        :breadcrumbs="[{ label: 'Operations' }, { label: 'Activities' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'activities.trip-cost')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Trip cost report
            </Link>
            <Button
                v-if="can.create"
                data-test="add-activity"
                @click="open = true"
            >
                New activity
            </Button>
        </template>
        <form
            class="mb-3 flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('activities.activities.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Title or number"
                aria-label="Search activities"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.type"
                aria-label="Type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any type
                </option>
                <option
                    v-for="t in types"
                    :key="t"
                    :value="t"
                >
                    {{ t.replace('_', ' ') }}
                </option>
            </select>
            <select
                v-model="f.status"
                aria-label="Status"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any status
                </option>
                <option
                    v-for="s in ['planned', 'ongoing', 'completed', 'cancelled']"
                    :key="s"
                    :value="s"
                >
                    {{ s }}
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
            :rows="activities"
            prefs-key="activities"
            empty-text="No activities yet."
        >
            <template #cell-activity_no="{ row }">
                <Link
                    :href="route('activities.activities.show', row.id)"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ row.activity_no }}
                </Link>
            </template>
            <template #cell-type="{ row }">
                {{ row.type.replace('_', ' ') }}
            </template>
            <template #cell-budget="{ row }">
                <Amount :value="row.budget" />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="New activity"
            @close="open = false"
        >
            <form
                id="activity-form"
                @submit.prevent="save"
            >
                <SchemaFields
                    :fields="fields"
                    :values="form"
                    :errors="form.errors"
                    id-prefix="act"
                    @set="(k, v) => setField(form, k, v)"
                />
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="activity-form"
                    :disabled="form.processing"
                >
                    Create
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
