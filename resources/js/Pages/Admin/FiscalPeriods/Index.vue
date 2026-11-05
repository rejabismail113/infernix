<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Period { id: number; period_no: number; starts_on: string; ends_on: string; locked_at: string | null; lock_reason: string | null }
defineProps<{ years: { id: number; year: number; status: string; periods: Period[] }[]; can: { lock: boolean; create: boolean } }>();
const yearForm = useForm({ year: new Date().getFullYear() + 1 });
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function toggle(p: Period) {
    const reason = window.prompt(`${p.locked_at ? 'Unlock' : 'Lock'} period ${p.period_no}: reason (audited)`);
    if (reason) router.post(route(p.locked_at ? 'admin.fiscal-periods.unlock' : 'admin.fiscal-periods.lock', p.id), { reason });
}
</script>

<template>
    <AppLayout
        title="Fiscal years & periods"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Fiscal periods' }]"
    >
        <template #actions>
            <form
                v-if="can.create"
                class="flex items-center gap-2"
                @submit.prevent="yearForm.post(route('admin.fiscal-years.store'))"
            >
                <input
                    v-model.number="yearForm.year"
                    type="number"
                    aria-label="Year"
                    class="w-24 rounded-md border border-surface-300 px-3 py-2 text-sm"
                >
                <Button type="submit">
                    Create fiscal year
                </Button>
            </form>
        </template>
        <p
            v-if="yearForm.errors.year"
            class="text-sm text-red-600"
            role="alert"
        >
            {{ yearForm.errors.year }}
        </p>
        <Card
            v-for="y in years"
            :key="y.id"
            :title="`FY ${y.year}`"
        >
            <template #actions>
                <StatusChip :status="y.status" />
            </template>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <div
                    v-for="p in y.periods"
                    :key="p.id"
                    class="rounded-lg border p-3 text-sm"
                    :class="p.locked_at ? 'border-sky-200 bg-sky-50/50' : 'border-surface-200'"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-semibold">P{{ p.period_no }} · {{ MONTHS[p.period_no - 1] }}</span>
                        <StatusChip :status="p.locked_at ? 'locked' : 'open'" />
                    </div>
                    <p class="mt-1 text-xs text-surface-500">
                        {{ p.starts_on }} → {{ p.ends_on }}
                    </p>
                    <button
                        v-if="can.lock"
                        type="button"
                        class="mt-2 text-xs font-medium text-brand-700 hover:underline"
                        @click="toggle(p)"
                    >
                        {{ p.locked_at ? 'Unlock…' : 'Lock…' }}
                    </button>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>
