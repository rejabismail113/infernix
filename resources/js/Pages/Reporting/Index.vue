<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{ reports: { key: string; name: string; module: string; href: string }[]; canExport: boolean }>();
const modules = computed(() => [...new Set(props.reports.map((r) => r.module))]);
</script>

<template>
    <AppLayout
        title="Reports"
        :breadcrumbs="[{ label: 'Reports' }]"
    >
        <template #actions>
            <Link
                v-if="canExport"
                :href="route('reports.exports.index')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                Export Centre
            </Link>
        </template>
        <Card
            v-for="m in modules"
            :key="m"
            :title="m"
        >
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="r in reports.filter((x) => x.module === m)"
                    :key="r.key"
                    :href="r.href"
                    class="flex items-center gap-3 rounded-md border border-surface-200 p-3 text-sm hover:border-brand-300 hover:bg-brand-50"
                >
                    <Icon
                        name="report"
                        class="text-brand-600"
                    /> {{ r.name }}
                </Link>
            </div>
        </Card>
    </AppLayout>
</template>
