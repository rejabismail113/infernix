<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Period { id: number; period_no: number; starts_on: string; ends_on: string; locked_at: string | null; lock_reason: string | null }
defineProps<{ years: { id: number; year: number; status: string; periods: Period[] }[]; can: { lock: boolean; create: boolean } }>();
const yearForm = useForm({ year: new Date().getFullYear() + 1 });
function toggle(p: Period) {
    const reason = window.prompt(`${p.locked_at ? 'Unlock' : 'Lock'} period ${p.period_no}: reason (audited)`);
    if (reason) router.post(route(p.locked_at ? 'admin.fiscal-periods.unlock' : 'admin.fiscal-periods.lock', p.id), { reason });
}
</script>

<template>
    <AppLayout>
        <Head title="Fiscal periods" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Fiscal years & periods
            </h1>
        </template>
        <section
            v-for="y in years"
            :key="y.id"
            class="mb-6"
        >
            <h2 class="mb-2 font-semibold">
                FY {{ y.year }} <span class="text-xs text-surface-500">({{ y.status }})</span>
            </h2>
            <div class="grid grid-cols-2 gap-2 md:grid-cols-6">
                <div
                    v-for="p in y.periods"
                    :key="p.id"
                    class="rounded-lg border bg-white p-3 text-sm"
                    :class="p.locked_at ? 'border-surface-300 bg-surface-100' : 'border-surface-200'"
                >
                    <div class="font-medium">
                        P{{ p.period_no }} · {{ p.starts_on.slice(5) }}→{{ p.ends_on.slice(5) }}
                    </div>
                    <div class="text-xs text-surface-500">
                        {{ p.locked_at ? 'LOCKED ' + p.locked_at.slice(0, 10) : 'open' }}
                    </div>
                    <button
                        v-if="can.lock"
                        class="mt-2 text-xs text-brand-700 hover:underline"
                        @click="toggle(p)"
                    >
                        {{ p.locked_at ? 'Unlock' : 'Lock' }}
                    </button>
                </div>
            </div>
        </section>
        <form
            v-if="can.create"
            class="flex max-w-sm gap-2"
            @submit.prevent="yearForm.post(route('admin.fiscal-years.store'))"
        >
            <input
                v-model.number="yearForm.year"
                type="number"
                aria-label="Year"
                class="rounded-md border border-surface-300 px-3 py-2"
            >
            <button
                type="submit"
                class="rounded-md bg-brand-600 px-4 py-2 text-white hover:bg-brand-700"
            >
                Create fiscal year
            </button>
            <p
                v-if="yearForm.errors.year"
                class="text-sm text-red-600"
            >
                {{ yearForm.errors.year }}
            </p>
        </form>
    </AppLayout>
</template>
