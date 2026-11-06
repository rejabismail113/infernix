<script setup lang="ts">
/**
 * Export Centre (M11-05): only reports this user may export are listed; the server re-checks, stores the file
 * in the Document Centre and redirects to the signed download. A plain form POST so the browser follows the
 * redirect to the file.
 */
import { usePage } from '@inertiajs/vue3';
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
}>();
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
    </AppLayout>
</template>
