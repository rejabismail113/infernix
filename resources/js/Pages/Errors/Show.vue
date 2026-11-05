<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

const props = defineProps<{ status: number; message?: string | null }>();
const page = usePage<SharedProps>();
const copy = computed(() => ({
    403: { title: 'Access denied', text: 'You do not have permission to view this page or record. If you think you should, ask the Owner to review your role.' },
    404: { title: 'Not found', text: 'The page or record does not exist or has been removed.' },
    419: { title: 'Session expired', text: 'Please refresh the page and try again.' },
    429: { title: 'Too many requests', text: 'Please wait a moment and try again.' },
    500: { title: 'Server error', text: 'Something went wrong on our side. The error has been logged.' },
    503: { title: 'Down for maintenance', text: 'Infernix One is being updated. Please try again shortly.' },
}[props.status] ?? { title: 'Error', text: 'Something went wrong.' }));
</script>

<template>
    <Head :title="copy.title" />
    <main class="flex min-h-screen items-center justify-center bg-surface-50 px-4">
        <div class="max-w-md rounded-xl border border-surface-200 bg-white p-8 text-center shadow-sm">
            <span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                <Icon
                    :name="status === 403 ? 'lock' : 'alert'"
                    :size="24"
                />
            </span>
            <p class="text-sm font-semibold text-brand-700">
                {{ status }}
            </p>
            <h1 class="mt-1 text-xl font-bold">
                {{ copy.title }}
            </h1>
            <p class="mt-2 text-sm text-surface-600">
                {{ copy.text }}
            </p>
            <Link
                :href="page.props.auth?.user ? '/' : '/login'"
                class="mt-6 inline-block rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                {{ page.props.auth?.user ? 'Back to dashboard' : 'Sign in' }}
            </Link>
        </div>
    </main>
</template>
