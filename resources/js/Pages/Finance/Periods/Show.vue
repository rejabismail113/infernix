<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import Icon from '@/Components/Icon.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ReportData } from '@/types/finance';

const props = defineProps<{
    period: { id: number; label: string; starts_on: string; ends_on: string; locked_at: string | null; lock_reason: string | null };
    checklist: { items: { key: string; label: string; count: number }[]; balanced: boolean; debits: string; credits: string; ready: boolean; trial_balance: ReportData };
    can: { lock: boolean };
}>();
function lock() {
    const reason = window.prompt(props.checklist.ready ? 'Lock this period: reason (audited). You will be asked for your authenticator code.' : 'Some checks are not clear. Lock anyway? Reason (audited):');
    if (reason) router.post(route(props.period.locked_at ? 'admin.fiscal-periods.unlock' : 'admin.fiscal-periods.lock', props.period.id), { reason });
}
</script>

<template>
    <AppLayout
        :title="`Close ${period.label}`"
        :status="period.locked_at ? 'locked' : 'open'"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Period close', href: route('finance.periods.index') }, { label: period.label }]"
    >
        <template #actions>
            <Button
                v-if="can.lock"
                :variant="period.locked_at ? 'secondary' : 'primary'"
                @click="lock"
            >
                {{ period.locked_at ? 'Unlock period…' : 'Lock period…' }}
            </Button>
        </template>
        <p
            v-if="period.locked_at"
            class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900"
        >
            Locked {{ period.locked_at.slice(0, 10) }} — {{ period.lock_reason }}. Corrections are made by reversal or an approved adjusting journal in an open period.
        </p>
        <Card :title="`Checklist · ${period.starts_on} – ${period.ends_on}`">
            <ul class="divide-y divide-surface-100 text-sm">
                <li
                    v-for="i in checklist.items"
                    :key="i.key"
                    class="flex items-center gap-3 py-2"
                >
                    <Icon
                        :name="i.count === 0 ? 'check' : 'alert'"
                        :class="i.count === 0 ? 'text-emerald-600' : 'text-amber-600'"
                    />
                    <span class="flex-1">{{ i.label }}</span>
                    <StatusChip
                        :status="i.count === 0 ? 'complete' : 'pending'"
                        :label="String(i.count)"
                    />
                </li>
                <li class="flex items-center gap-3 py-2">
                    <Icon
                        :name="checklist.balanced ? 'check' : 'alert'"
                        :class="checklist.balanced ? 'text-emerald-600' : 'text-red-600'"
                    />
                    <span class="flex-1">Period journals balance (debits <Amount :value="checklist.debits" /> = credits <Amount :value="checklist.credits" />)</span>
                    <StatusChip :status="checklist.balanced ? 'complete' : 'rejected'" />
                </li>
            </ul>
        </Card>
        <Card title="Trial balance preview (as at period end)">
            <DataTable
                :columns="checklist.trial_balance.columns"
                :rows="checklist.trial_balance.rows"
            >
                <template #cell-debit="{ row }">
                    <Amount
                        v-if="row.debit !== '0.00'"
                        :value="String(row.debit)"
                    />
                </template>
                <template #cell-credit="{ row }">
                    <Amount
                        v-if="row.credit !== '0.00'"
                        :value="String(row.credit)"
                    />
                </template>
                <template #footer>
                    <tr class="border-t font-semibold">
                        <td
                            colspan="3"
                            class="px-3 py-2 text-right"
                        >
                            Total
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Amount :value="checklist.trial_balance.totals.debit" />
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Amount :value="checklist.trial_balance.totals.credit" />
                        </td>
                    </tr>
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
