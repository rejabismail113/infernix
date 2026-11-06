<script setup lang="ts">
/** Go-live data migration (§9.1): template → dry run (validation report) → import. Owner only. */
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FileDropzone from '@/Components/Form/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Report { type: string; dry_run: boolean; rows: number; valid: number; created: number; errors: { row: number; field: string; message: string }[]; preview: Record<string, string | null>[]; journal_id?: number | null }
const props = defineProps<{ types: Record<string, string[]> }>();
const page = usePage<{ flash: { data_import?: Report | null } }>();
const report = computed(() => page.props.flash.data_import ?? null);
const form = useForm<{ type: string; file: File | null; dry_run: boolean; date: string }>({ type: Object.keys(props.types)[0] ?? 'clients', file: null, dry_run: true, date: '' });
const submit = (dry: boolean) => { form.dry_run = dry; form.post(route('admin.imports.run'), { forceFormData: true, preserveScroll: true }); };
</script>

<template>
    <AppLayout
        title="Data import"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Data import' }]"
    >
        <Card
            title="Import go-live data from CSV"
            description="Every row is validated first. If any row has a problem nothing is written. Run a dry run, fix the file, then import."
        >
            <form
                class="grid gap-3 md:grid-cols-3"
                @submit.prevent="submit(true)"
            >
                <FormField
                    id="imp-type"
                    label="What"
                    :error="form.errors.type"
                >
                    <select
                        id="imp-type"
                        v-model="form.type"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                        <option
                            v-for="(cols, t) in types"
                            :key="t"
                            :value="t"
                        >
                            {{ String(t).replace('_', ' ') }}
                        </option>
                    </select>
                    <a
                        :href="route('admin.imports.template', form.type)"
                        class="mt-1 inline-block text-xs text-brand-700 hover:underline"
                    >Download the {{ form.type.replace('_', ' ') }} template</a>
                    <p class="mt-1 text-xs text-surface-500">
                        Columns: {{ types[form.type]?.join(', ') }}
                    </p>
                </FormField>
                <FormField
                    v-if="form.type === 'opening_balances'"
                    id="imp-date"
                    label="Opening balance date"
                >
                    <input
                        id="imp-date"
                        v-model="form.date"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-2 py-2 text-sm"
                    >
                </FormField>
                <div class="md:col-span-3">
                    <FileDropzone
                        id="imp-file"
                        v-model="form.file"
                        :accept="['csv']"
                        label="CSV file (UTF-8)"
                        :error="form.errors.file"
                    />
                </div>
                <div class="flex gap-2 md:col-span-3">
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="!form.file || form.processing"
                        data-test="dry-run"
                    >
                        Dry run (validate only)
                    </Button>
                    <Button
                        :disabled="!form.file || form.processing || !report || report.dry_run === false || report.errors.length > 0 || report.type !== form.type"
                        data-test="import"
                        @click="submit(false)"
                    >
                        Import
                    </Button>
                </div>
            </form>
        </Card>
        <Card
            v-if="report"
            :title="`${report.dry_run ? 'Dry run' : 'Import'}: ${report.type.replace('_', ' ')}`"
            class="mt-5"
        >
            <p
                class="text-sm"
                data-test="import-summary"
            >
                {{ report.rows }} rows · {{ report.valid }} valid · {{ report.created }} created
                <span
                    v-if="report.dry_run && !report.errors.length"
                    class="text-emerald-700"
                > — ready to import</span>
            </p>
            <table
                v-if="report.errors.length"
                class="mt-3 w-full text-sm"
            >
                <thead class="text-left text-surface-500">
                    <tr>
                        <th>Row</th><th>Field</th><th>Problem</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(e, i) in report.errors"
                        :key="i"
                        class="border-t border-surface-100"
                    >
                        <td class="py-1">
                            {{ e.row || '—' }}
                        </td><td>{{ e.field }}</td><td class="text-red-700">
                            {{ e.message }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
