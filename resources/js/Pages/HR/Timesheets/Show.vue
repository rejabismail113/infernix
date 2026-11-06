<script setup lang="ts">
/** Timesheet: entries against assigned projects (UAT-07), PM verification (M04-03), calculation at the locked rate (WF-05). */
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import Icon from '@/Components/Icon.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { sumDecimals } from '@/lib/decimal';
import { fieldError } from '@/lib/errors';

interface Entry { id?: number; project_id: number; project?: string; work_date: string; quantity: string; description: string | null; exception_granted?: boolean }
const props = defineProps<{
    timesheet: { id: number; staff: string; staff_no: string; period_start: string; period_end: string; status: string; total_quantity: string; rate: string | null; amount: string | null; classification: string; rejection_reason: string | null; rate_type: string | null };
    entries: Entry[]; projectOptions: { id: number; label: string }[]; unit: string;
    can: { edit: boolean; submit: boolean; verify: boolean; calculate: boolean };
}>();
const form = useForm({ entries: props.entries.map((e) => ({ project_id: e.project_id as number | '', work_date: e.work_date, quantity: e.quantity, description: e.description ?? '' })) });
const add = () => form.entries.push({ project_id: props.projectOptions[0]?.id ?? '', work_date: props.timesheet.period_start, quantity: '', description: '' });
const total = computed(() => sumDecimals(form.entries.map((e) => String(e.quantity || '0'))));
const save = () => form.put(route('hr.timesheets.entries', props.timesheet.id), { preserveScroll: true });
const verify = (approve: boolean) => {
    const reason = approve ? null : window.prompt('Why is this timesheet being returned?');
    if (!approve && !reason) return;
    router.post(route('hr.timesheets.verify', props.timesheet.id), { approve, reason }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout
        :title="`Timesheet ${timesheet.period_start} → ${timesheet.period_end}`"
        :status="timesheet.status"
        :breadcrumbs="[{ label: 'People' }, { label: 'Timesheets', href: route('hr.timesheets.index') }, { label: timesheet.staff }]"
    >
        <template #actions>
            <Button
                v-if="can.submit"
                data-test="submit-timesheet"
                @click="router.post(route('hr.timesheets.submit', timesheet.id))"
            >
                Submit
            </Button>
            <Button
                v-if="can.verify"
                data-test="verify"
                @click="verify(true)"
            >
                Verify
            </Button>
            <Button
                v-if="can.verify"
                variant="secondary"
                @click="verify(false)"
            >
                Return
            </Button>
            <Button
                v-if="can.calculate"
                data-test="calculate"
                @click="router.post(route('hr.timesheets.calculate', timesheet.id), {}, { preserveScroll: true })"
            >
                Calculate payment
            </Button>
        </template>
        <div
            v-if="timesheet.rejection_reason && timesheet.status === 'rejected'"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
        >
            Returned: {{ timesheet.rejection_reason }}
        </div>
        <Card>
            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-surface-500">
                        Staff
                    </dt><dd class="font-medium">
                        {{ timesheet.staff }} <span class="text-surface-500">({{ timesheet.staff_no }})</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Total
                    </dt><dd>{{ timesheet.total_quantity }} {{ unit }}s</dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Locked rate
                    </dt><dd>
                        <Amount
                            v-if="timesheet.rate"
                            :value="timesheet.rate"
                        /><span v-else>—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Amount
                    </dt><dd class="text-lg font-semibold">
                        <Amount
                            v-if="timesheet.amount"
                            :value="timesheet.amount"
                        /><span v-else>—</span>
                    </dd>
                </div>
            </dl>
        </Card>
        <Card :title="`Entries (${unit}s)`">
            <p
                v-if="fieldError(form.errors, 'general')"
                class="mb-2 rounded-md bg-red-50 p-2 text-sm text-red-800"
                data-test="entry-error"
            >
                {{ fieldError(form.errors, 'general') }}
            </p>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-surface-500">
                    <tr>
                        <th class="py-2">
                            Date
                        </th><th>Project</th><th class="w-28">
                            Quantity
                        </th><th>Description</th><th class="w-8" />
                    </tr>
                </thead>
                <tbody v-if="can.edit">
                    <tr
                        v-for="(e, i) in form.entries"
                        :key="i"
                        class="border-t border-surface-100"
                        :data-test="`entry-${i}`"
                    >
                        <td class="py-1 pr-2">
                            <input
                                v-model="e.work_date"
                                type="date"
                                :min="timesheet.period_start"
                                :max="timesheet.period_end"
                                :aria-label="`Entry ${i + 1} date`"
                                class="rounded border border-surface-300 px-2 py-1"
                            >
                        </td>
                        <td class="pr-2">
                            <select
                                v-model="e.project_id"
                                :aria-label="`Entry ${i + 1} project`"
                                class="w-full rounded border border-surface-300 px-2 py-1"
                            >
                                <option
                                    v-for="p in projectOptions"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.label }}
                                </option>
                            </select>
                        </td>
                        <td class="pr-2">
                            <input
                                v-model="e.quantity"
                                inputmode="decimal"
                                :aria-label="`Entry ${i + 1} quantity`"
                                class="w-full rounded border border-surface-300 px-2 py-1 text-right"
                            >
                        </td>
                        <td class="pr-2">
                            <input
                                v-model="e.description"
                                :aria-label="`Entry ${i + 1} description`"
                                class="w-full rounded border border-surface-300 px-2 py-1"
                            >
                        </td>
                        <td>
                            <button
                                type="button"
                                class="p-1 text-surface-400 hover:text-red-600"
                                :aria-label="`Remove entry ${i + 1}`"
                                @click="form.entries.splice(i, 1)"
                            >
                                <Icon
                                    name="close"
                                    :size="14"
                                />
                            </button>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr
                        v-for="e in entries"
                        :key="e.id"
                        class="border-t border-surface-100"
                    >
                        <td class="py-1.5">
                            {{ e.work_date }}
                        </td><td>
                            {{ e.project }} <StatusChip
                                v-if="e.exception_granted"
                                status="pending"
                                label="exception"
                            />
                        </td><td class="text-right">
                            {{ e.quantity }}
                        </td><td>{{ e.description }}</td><td />
                    </tr>
                </tbody>
            </table>
            <div
                v-if="can.edit"
                class="mt-3 flex flex-wrap items-center gap-2"
            >
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="!projectOptions.length"
                    data-test="add-entry"
                    @click="add"
                >
                    Add entry
                </Button>
                <Button
                    :disabled="form.processing"
                    data-test="save-entries"
                    @click="save"
                >
                    Save
                </Button>
                <span class="ml-auto text-sm">Total: <strong>{{ total }}</strong> {{ unit }}s</span>
                <p
                    v-if="!projectOptions.length"
                    class="w-full text-sm text-amber-700"
                >
                    You are not assigned to any project in this period. Ask your project manager to add you to the team.
                </p>
            </div>
        </Card>
    </AppLayout>
</template>
