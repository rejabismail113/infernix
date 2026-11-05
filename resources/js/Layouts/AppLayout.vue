<script setup lang="ts">
/**
 * Application shell (reference layout: sidebar + breadcrumb + title/status chip + content cards).
 * Usage: <AppLayout title="Users" :breadcrumbs="[...]" status="active"> … <template #actions>…</template>
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import ToastHost from '@/Components/Feedback/ToastHost.vue';
import Icon from '@/Components/Icon.vue';
import Breadcrumbs, { type Crumb } from '@/Components/Shell/Breadcrumbs.vue';
import PageHeader from '@/Components/Shell/PageHeader.vue';
import Sidebar from '@/Components/Shell/Sidebar.vue';
import type { SharedProps } from '@/types';

withDefaults(defineProps<{ title?: string; breadcrumbs?: Crumb[]; status?: string | null; statusLabel?: string }>(), {
    title: '', breadcrumbs: () => [], status: null, statusLabel: undefined,
});

const page = usePage<SharedProps>();
const mobileOpen = ref(false);
</script>

<template>
    <Head :title="title" />
    <a
        href="#main"
        class="sr-only z-50 rounded bg-white px-3 py-2 focus:not-sr-only focus:fixed focus:left-2 focus:top-2"
    >Skip to content</a>
    <div class="flex min-h-screen bg-surface-50">
        <aside class="hidden w-64 shrink-0 border-r border-surface-200 bg-white lg:block">
            <div class="sticky top-0 h-screen">
                <Sidebar
                    :items="page.props.nav ?? []"
                    :current-url="page.url"
                    :company-name="page.props.app.company.name"
                />
            </div>
        </aside>
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-40 flex lg:hidden"
        >
            <div
                class="w-64 bg-white shadow-xl"
                @keydown.esc="mobileOpen = false"
            >
                <Sidebar
                    :items="page.props.nav ?? []"
                    :current-url="page.url"
                    @navigate="mobileOpen = false"
                />
            </div>
            <button
                type="button"
                class="flex-1 bg-surface-900/30"
                aria-label="Close menu"
                @click="mobileOpen = false"
            />
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center justify-between gap-4 border-b border-surface-200 bg-white px-4 lg:px-8">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded p-1.5 text-surface-600 hover:bg-surface-100 lg:hidden"
                        aria-label="Open menu"
                        @click="mobileOpen = true"
                    >
                        <Icon name="menu" />
                    </button>
                    <Breadcrumbs :items="breadcrumbs" />
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <span
                        class="hidden text-surface-700 sm:inline"
                        data-test="user-name"
                    >{{ page.props.auth.user?.name }}</span>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-surface-300 px-3 py-1.5 hover:bg-surface-100"
                    >
                        <Icon
                            name="logout"
                            :size="16"
                        /> Log out
                    </Link>
                </div>
            </header>
            <main
                id="main"
                class="flex-1 space-y-5 px-4 py-6 lg:px-8"
                tabindex="-1"
            >
                <PageHeader
                    v-if="title"
                    :title="title"
                    :status="status"
                    :status-label="statusLabel"
                >
                    <template #chips>
                        <slot name="chips" />
                    </template>
                    <template #actions>
                        <slot name="actions" />
                    </template>
                </PageHeader>
                <slot />
            </main>
        </div>
    </div>
    <ToastHost />
</template>
