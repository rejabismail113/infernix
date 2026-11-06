<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { SharedProps } from '@/types';

defineProps<{ company: { name: string; tagline: string } }>();
const page = usePage<SharedProps>();
</script>

<template>
    <AppLayout
        title="Dashboard"
        :breadcrumbs="[{ label: 'Home' }]"
    >
        <Card>
            <h2 class="text-xl font-semibold">
                {{ company.name }}
            </h2>
            <p class="text-surface-500">
                {{ company.tagline }}
            </p>
            <p class="mt-4 text-sm text-surface-600">
                Everything you have access to is below. The owner and finance dashboards show revenue, cash, costs and what is outstanding.
            </p>
        </Card>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                v-for="item in (page.props.nav ?? []).filter((n) => n.href !== route('dashboard'))"
                :key="item.href"
                :href="item.href"
                class="flex items-center gap-3 rounded-lg border border-surface-200 bg-white p-4 text-sm font-medium hover:border-brand-300 hover:bg-brand-50"
            >
                <span class="rounded-md bg-brand-50 p-2 text-brand-600"><Icon :name="item.icon" /></span>
                {{ item.label }}
            </Link>
        </div>
    </AppLayout>
</template>
