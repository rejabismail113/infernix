<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';

interface StaffRow { id: number; staff_no: string; name: string; legal_name: string; id_masked: string | null; engagement_type: string; classification: string; position: string | null; joined_on: string | null; status: string; has_user: boolean }
const props = defineProps<{ staff: StaffRow[]; filters: Record<string, string | null>; users: { id: number; label: string }[]; can: { create: boolean } }>();
const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', engagement_type: props.filters.engagement_type ?? '' });
const open = ref(false);
const form = useForm({ legal_name: '', preferred_name: '', engagement_type: 'part_time', classification: 'contractor', id_type: 'nric', id_number: '', email: '', phone: '', position: '', joined_on: '', user_id: '' as number | '' });
const save = () => form.transform((d) => ({ ...d, user_id: d.user_id || null, joined_on: d.joined_on || null, id_number: d.id_number || null })).post(route('hr.staff.store'));
</script>

<template>
    <AppLayout
        title="Staff directory"
        :breadcrumbs="[{ label: 'People' }, { label: 'Staff directory' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'hr.roster')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Roster report
            </Link>
            <Button
                v-if="can.create"
                data-test="add-staff"
                @click="open = true"
            >
                Add staff
            </Button>
        </template>
        <form
            class="flex flex-wrap gap-2 rounded-lg border border-surface-200 bg-white p-3 text-sm"
            role="search"
            @submit.prevent="router.get(route('hr.staff.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true })"
        >
            <input
                v-model="f.q"
                type="search"
                placeholder="Name or staff no."
                aria-label="Search"
                class="w-64 rounded-md border border-surface-300 px-3 py-2"
            >
            <select
                v-model="f.engagement_type"
                aria-label="Engagement"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any engagement
                </option>
                <option
                    v-for="e in ['employee', 'part_time', 'freelancer', 'intern']"
                    :key="e"
                    :value="e"
                >
                    {{ e.replace('_', ' ') }}
                </option>
            </select>
            <select
                v-model="f.status"
                aria-label="Status"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    Any status
                </option><option value="active">
                    Active
                </option><option value="inactive">
                    Inactive
                </option>
            </select>
            <Button type="submit">
                Filter
            </Button>
        </form>
        <DataTable
            :columns="[{ key: 'staff_no', label: 'Staff no', hideable: false }, { key: 'name', label: 'Name' }, { key: 'id_masked', label: 'NRIC / passport' }, { key: 'engagement_type', label: 'Engagement' }, { key: 'classification', label: 'Classification' }, { key: 'position', label: 'Position' }, { key: 'joined_on', label: 'Joined' }, { key: 'status', label: 'Status' }]"
            :rows="staff"
            prefs-key="hr.staff"
            empty-text="No staff yet."
        >
            <template #cell-staff_no="{ row }">
                <Link
                    :href="route('hr.staff.show', row.id)"
                    class="font-mono font-medium text-brand-700 hover:underline"
                >
                    {{ row.staff_no }}
                </Link>
            </template>
            <template #cell-id_masked="{ row }">
                <span
                    class="font-mono text-surface-500"
                    data-test="masked-id"
                >{{ row.id_masked ?? '—' }}</span>
            </template>
            <template #cell-engagement_type="{ row }">
                {{ row.engagement_type.replace('_', ' ') }}
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>
        <Modal
            :open="open"
            title="Add staff"
            size="lg"
            @close="open = false"
        >
            <form
                id="staff-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="save"
            >
                <FormField
                    id="st-legal"
                    label="Legal name (as in NRIC)"
                    :error="form.errors.legal_name"
                    class="col-span-2"
                >
                    <input
                        id="st-legal"
                        v-model="form.legal_name"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-pref"
                    label="Preferred name"
                >
                    <input
                        id="st-pref"
                        v-model="form.preferred_name"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-pos"
                    label="Position"
                >
                    <input
                        id="st-pos"
                        v-model="form.position"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-eng"
                    label="Engagement"
                >
                    <select
                        id="st-eng"
                        v-model="form.engagement_type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                        @change="form.classification = form.engagement_type === 'employee' ? 'employee' : form.classification"
                    >
                        <option value="employee">
                            Employee
                        </option><option value="part_time">
                            Part-time
                        </option><option value="freelancer">
                            Freelancer
                        </option><option value="intern">
                            Intern
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="st-class"
                    label="Classification (after review)"
                    :error="form.errors.classification"
                >
                    <select
                        id="st-class"
                        v-model="form.classification"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="employee">
                            Employee — statutory obligations
                        </option><option value="contractor">
                            Contractor — invoice required before payment
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="st-idtype"
                    label="ID type"
                >
                    <select
                        id="st-idtype"
                        v-model="form.id_type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="nric">
                            NRIC
                        </option><option value="passport">
                            Passport
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="st-id"
                    label="ID number (stored encrypted, shown masked)"
                    :error="form.errors.id_number || fieldError(form.errors, 'general')"
                >
                    <input
                        id="st-id"
                        v-model="form.id_number"
                        autocomplete="off"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-email"
                    label="Email"
                    :error="form.errors.email"
                >
                    <input
                        id="st-email"
                        v-model="form.email"
                        type="email"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-phone"
                    label="Phone"
                >
                    <input
                        id="st-phone"
                        v-model="form.phone"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-joined"
                    label="Joined"
                >
                    <input
                        id="st-joined"
                        v-model="form.joined_on"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="st-user"
                    label="Login account (for self-service)"
                >
                    <select
                        id="st-user"
                        v-model="form.user_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            — none —
                        </option>
                        <option
                            v-for="u in users"
                            :key="u.id"
                            :value="u.id"
                        >
                            {{ u.label }}
                        </option>
                    </select>
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
                    form="staff-form"
                    :disabled="form.processing"
                >
                    Add staff
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
