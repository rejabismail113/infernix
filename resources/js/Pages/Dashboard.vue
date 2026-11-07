<script setup lang="ts">
/**
 * Home dashboard: time-of-day greeting with the user's first name, today's date / time (Malaysia time), and
 * tiles for everything the user may open.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { SharedProps } from '@/types';

defineProps<{ company: { name: string; tagline: string } }>();
const page = usePage<SharedProps>();

const TZ = 'Asia/Kuala_Lumpur';
const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => { timer = setInterval(() => { now.value = new Date(); }, 30_000); });
onBeforeUnmount(() => clearInterval(timer));

const hour = computed(() => Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: TZ }).format(now.value)));
const greeting = computed(() => (hour.value < 12 ? 'Good Morning' : hour.value < 17 ? 'Good Afternoon' : 'Good Evening'));
/** "Priya (Project manager)" → "Priya" */
const firstName = computed(() => (page.props.auth.user?.name ?? '').replace(/\s*\(.*\)\s*$/, '').trim().split(/\s+/)[0] ?? '');
const dateText = computed(() => new Intl.DateTimeFormat('en-MY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ }).format(now.value));
const timeText = computed(() => new Intl.DateTimeFormat('en-MY', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: TZ }).format(now.value).toUpperCase());
</script>

<template>
    <AppLayout
        title="Dashboard"
        :breadcrumbs="[{ label: 'Home' }]"
    >
        <template #actions>
            <div
                class="flex items-center gap-2 rounded-full border border-surface-200 bg-white px-3.5 py-1.5 text-sm text-surface-600 shadow-sm"
                data-test="today"
            >
                <svg
                    class="h-4 w-4 text-brand-600"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                ><rect
                    x="3"
                    y="5"
                    width="18"
                    height="16"
                    rx="2"
                /><path d="M3 10h18M8 3v4M16 3v4" /></svg>
                <span>Today is <span class="font-medium text-surface-800">{{ dateText }}</span></span>
                <span
                    class="text-surface-300"
                    aria-hidden="true"
                >|</span>
                <time class="font-medium tabular-nums text-surface-800">{{ timeText }}</time>
            </div>
        </template>
        <Card>
            <h2
                class="text-2xl font-semibold tracking-tight text-surface-900 sm:text-3xl"
                data-test="greeting"
            >
                {{ greeting }}{{ firstName ? `, ${firstName}` : '' }} <span aria-hidden="true">👋</span>
            </h2>
            <p class="mt-1 text-surface-500">
                Track, organise and manage your business activities at {{ company.name }}.
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
