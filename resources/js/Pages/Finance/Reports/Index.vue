<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{ reports: Record<string, string>; year: number; canExport: boolean }>();
const year = ref(props.year);
</script>

<template>
    <AppLayout
        title="Finance reports"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Reports' }]"
    >
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="(name, key) in reports"
                :key="key"
                :href="route('finance.reports.show', key)"
                class="flex items-center gap-3 rounded-lg border border-surface-200 bg-white p-4 text-sm font-medium hover:border-brand-300 hover:bg-brand-50"
            >
                <span class="rounded-md bg-brand-50 p-2 text-brand-600"><Icon name="report" /></span>
                {{ name }}
            </Link>
        </div>
        <Card
            v-if="canExport"
            title="Tax preparation pack"
            description="ZIP for your accountant: journals, chart of accounts, trial balance, vouchers, evidence list, invoices, bank movement and review flags. Bookkeeping output — not a tax filing. The download is audited."
        >
            <div class="flex items-center gap-2">
                <input
                    v-model.number="year"
                    type="number"
                    aria-label="Year"
                    class="w-28 rounded-md border border-surface-300 px-3 py-2 text-sm"
                >
                <a
                    :href="route('finance.reports.tax-pack', { year })"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                >Download pack</a>
            </div>
        </Card>
    </AppLayout>
</template>
