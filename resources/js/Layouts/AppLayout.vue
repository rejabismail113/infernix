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
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { router } from '@inertiajs/vue3';

withDefaults(defineProps<{ title?: string; subtitle?: string | null; breadcrumbs?: Crumb[]; status?: string | null; statusLabel?: string }>(), {
    title: '', subtitle: null, breadcrumbs: () => [], status: null, statusLabel: undefined,
});

const page = usePage<SharedProps>();
const mobileOpen = ref(false);
const t = useT();
const setLocale = (locale: 'en' | 'ms') => router.post(route('locale.update'), { locale }, { preserveScroll: true });
</script>

<template>
    <Head :title="title" />
    <a
        href="#main"
        class="sr-only z-50 rounded bg-white px-3 py-2 focus:not-sr-only focus:fixed focus:left-2 focus:top-2"
    >{{ t('Skip to content') }}</a>
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
                :aria-label="t('Close menu')"
                @click="mobileOpen = false"
            />
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center justify-between gap-4 border-b border-surface-200 bg-white px-4 lg:px-8">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded p-1.5 text-surface-600 hover:bg-surface-100 lg:hidden"
                        :aria-label="t('Open menu')"
                        @click="mobileOpen = true"
                    >
                        <Icon name="menu" />
                    </button>
                    <Breadcrumbs :items="breadcrumbs" />
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <Link
                        :href="route('notifications.index')"
                        class="relative rounded-md p-1.5 text-surface-600 hover:bg-surface-100"
                        :aria-label="`${t('Notifications')} (${page.props.notifications?.unread ?? 0} ${t('unread')})`"
                        data-test="bell"
                    >
                        <Icon name="bell" />
                        <span
                            v-if="(page.props.notifications?.unread ?? 0) > 0"
                            class="absolute -right-1 -top-1 min-w-[1.1rem] rounded-full bg-brand-600 px-1 text-center text-[10px] font-semibold leading-4 text-white"
                        >{{ page.props.notifications?.unread }}</span>
                    </Link>
                    <div
                        class="flex overflow-hidden rounded-md border border-surface-300 text-xs"
                        role="group"
                        :aria-label="t('Language')"
                        data-test="locale-toggle"
                    >
                        <button
                            v-for="l in (['en', 'ms'] as const)"
                            :key="l"
                            type="button"
                            class="px-2 py-1"
                            :class="(page.props.i18n?.locale ?? 'en') === l ? 'bg-brand-600 text-white' : 'hover:bg-surface-100'"
                            :aria-pressed="(page.props.i18n?.locale ?? 'en') === l"
                            :lang="l"
                            @click="setLocale(l)"
                        >
                            {{ l === 'en' ? 'EN' : 'BM' }}
                        </button>
                    </div>
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
                        /> {{ t('Log out') }}
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
                    :subtitle="subtitle"
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
