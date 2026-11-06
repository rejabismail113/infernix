<script setup lang="ts">
/**
 * Export Centre (M11-05): only reports this user may export are listed; the server re-checks, stores the file
 * in the Document Centre and redirects to the signed download. A plain form POST so the browser follows the
 * redirect to the file.
 */
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Report { key: string; name: string; module: string; filters: string[] }
const props = defineProps<{
    reports: Report[]; preselect: string | null; defaults: { from: string; to: string; as_of: string };
    history: { id: number; report: string; format: string; filters: Record<string, string>; rows: number; created_at: string; download: string | null }[];
    schedules?: { id: number; report: string; format: string; frequency: string; day: number | null; recipients: string[]; active: boolean; last_run_at: string | null; mine: boolean }[];
    users?: { id: number; label: string }[];
}>();
// Scheduled emails: every recipient is re-checked at send time and gets their own row-scoped export.
const sched = useForm({ report: props.reports[0]?.key ?? '', format: 'xlsx', frequency: 'weekly', day: '1', recipient_ids: [] as number[] });
const saveSchedule = () => sched.post(route('reports.schedules.store'), { preserveScroll: true, onSuccess: () => sched.reset('recipient_ids') });
const toggle = (id: number) => router.post(route('reports.schedules.toggle', id), {}, { preserveScroll: true });
const page = usePage<{ errors: Record<string, string> }>();
const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
const report = ref(props.reports.find((r) => r.key === props.preselect)?.key ?? props.reports[0]?.key ?? '');
const current = computed(() => props.reports.find((r) => r.key === report.value));
const uses = (f: string) => current.value?.filters.includes(f) ?? false;
const modules = computed(() => [...new Set(props.reports.map((r) => r.module))]);
</script>

<template>
    <AppLayout
        title="Export Centre"
        :breadcrumbs="[{ label: 'Reports' }, { label: 'Export Centre' }]"
    >
        <Card
            title="New export"
            description="Exports are audited, scrubbed of secrets and stored privately in the Document Centre. Only you can see your exports."
        >
            <form
                method="post"
                :action="route('reports.exports.store')"
                class="grid gap-4 md:grid-cols-4"
                data-test="export-form"
            >
                <input
                    type="hidden"
                    name="_token"
                    :value="csrf"
                >
                <FormField
                    id="report"
                    label="Report"
                    :error="page.props.errors?.report"
                    class="md:col-span-2"
                >
                    <select
                        id="report"
                        v-model="report"
                        name="report"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <optgroup
                            v-for="m in modules"
                            :key="m"
                            :label="m"
                        >
                            <option
                                v-for="r in reports.filter((x) => x.module === m)"
                                :key="r.key"
                                :value="r.key"
                            >
                                {{ r.name }}
                            </option>
                        </optgroup>
                    </select>
                </FormField>
                <FormField
                    id="format"
                    label="Format"
                >
                    <select
                        id="format"
                        name="format"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <option value="xlsx">
                            Excel (.xlsx)
                        </option><option value="csv">
                            CSV
                        </option>
                    </select>
                </FormField>
                <div />
                <FormField
                    v-if="uses('from')"
                    id="from"
                    label="From"
                >
                    <input
                        id="from"
                        name="from"
                        type="date"
                        :value="defaults.from"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    v-if="uses('to')"
                    id="to"
                    label="To"
                >
                    <input
                        id="to"
                        name="to"
                        type="date"
                        :value="defaults.to"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    v-if="uses('as_of')"
                    id="as_of"
                    label="As at"
                >
                    <input
                        id="as_of"
                        name="as_of"
                        type="date"
                        :value="defaults.as_of"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    v-if="uses('basis')"
                    id="basis"
                    label="Basis"
                >
                    <select
                        id="basis"
                        name="basis"
                        class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                    >
                        <option value="accrual">
                            Accrual
                        </option><option value="cash">
                            Cash (indicative)
                        </option>
                    </select>
                </FormField>
                <div class="md:col-span-4">
                    <Button
                        type="submit"
                        data-test="export"
                    >
                        Export &amp; download
                    </Button>
                </div>
            </form>
        </Card>
        <Card title="Your recent exports">
            <DataTable
                :columns="[{ key: 'created_at', label: 'When' }, { key: 'report', label: 'Report' }, { key: 'format', label: 'Format' }, { key: 'rows', label: 'Rows', numeric: true }, { key: 'download', label: '' }]"
                :rows="history"
                empty-text="No exports yet."
            >
                <template #cell-created_at="{ row }">
                    {{ row.created_at.slice(0, 16).replace('T', ' ') }}
                </template>
                <template #cell-format="{ row }">
                    {{ row.format.toUpperCase() }}
                </template>
                <template #cell-download="{ row }">
                    <a
                        v-if="row.download"
                        :href="row.download"
                        class="text-brand-700 hover:underline"
                    >Download again</a>
                </template>
            </DataTable>
        </Card>
        <Card
            title="Scheduled report emails"
            description="Sent from the server on schedule. Each recipient receives a link to an export made with their own permissions; anyone no longer allowed is skipped."
            class="mt-5"
        >
            <ul
                v-if="schedules?.length"
                class="mb-4 divide-y divide-surface-100 text-sm"
            >
                <li
                    v-for="s in schedules"
                    :key="s.id"
                    class="flex flex-wrap items-center gap-2 py-2"
                >
                    <span class="font-medium">{{ s.report }}</span>
                    <span class="text-surface-500">{{ s.frequency }}{{ s.day ? ` (day ${s.day})` : '' }} · {{ s.format.toUpperCase() }} · to {{ s.recipients.join(', ') }}</span>
                    <span class="text-xs text-surface-400">{{ s.last_run_at ? `last sent ${s.last_run_at.slice(0, 10)}` : 'not sent yet' }}</span>
                    <button
                        v-if="s.mine"
                        type="button"
                        class="ml-auto text-xs text-brand-700 hover:underline"
                        @click="toggle(s.id)"
                    >
                        {{ s.active ? 'Pause' : 'Resume' }}
                    </button>
                </li>
            </ul>
            <form
                class="grid gap-3 md:grid-cols-5"
                @submit.prevent="saveSchedule"
            >
                <FormField
                    id="sched-report"
                    label="Report"
                    :error="sched.errors.report"
                >
                    <select
                        id="sched-report"
                        v-model="sched.report"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                        <option
                            v-for="r in reports"
                            :key="r.key"
                            :value="r.key"
                        >
                            {{ r.name }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="sched-frequency"
                    label="Frequency"
                >
                    <select
                        id="sched-frequency"
                        v-model="sched.frequency"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                        <option value="daily">
                            Daily
                        </option>
                        <option value="weekly">
                            Weekly
                        </option>
                        <option value="monthly">
                            Monthly
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="sched-day"
                    :label="sched.frequency === 'monthly' ? 'Day of month' : 'Weekday (1 = Mon)'"
                >
                    <input
                        id="sched-day"
                        v-model="sched.day"
                        :disabled="sched.frequency === 'daily'"
                        inputmode="numeric"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                </FormField>
                <FormField
                    id="sched-recipients"
                    label="Recipients"
                    :error="sched.errors.recipient_ids"
                >
                    <select
                        id="sched-recipients"
                        v-model="sched.recipient_ids"
                        multiple
                        class="h-24 w-full rounded-md border border-surface-300 px-2 py-1 text-sm"
                    >
                        <option
                            v-for="u in users ?? []"
                            :key="u.id"
                            :value="u.id"
                        >
                            {{ u.label }}
                        </option>
                    </select>
                </FormField>
                <div class="flex items-end">
                    <Button
                        type="submit"
                        :disabled="sched.processing || !sched.recipient_ids.length"
                    >
                        Schedule
                    </Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>