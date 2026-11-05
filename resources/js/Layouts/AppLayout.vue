<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types';

/**
 * Temporary shell. Prompt 1c replaces this with the full sidebar/breadcrumb/DataTable shell
 * modelled on docs/reference/*.jpg.
 */
const page = usePage<SharedProps>();
</script>

<template>
    <div class="flex min-h-screen bg-surface-50">
        <aside class="hidden w-60 flex-col border-r border-surface-200 bg-white md:flex">
            <div class="flex h-16 items-center gap-2 px-4">
                <img
                    src="/images/infernix-logo.svg"
                    alt=""
                    class="h-9"
                >
                <span class="font-semibold">Infernix One</span>
            </div>
            <nav
                class="flex-1 px-2 py-2 text-sm"
                aria-label="Main"
            >
                <Link
                    :href="route('dashboard')"
                    class="block rounded-md bg-brand-50 px-3 py-2 font-medium text-brand-700"
                >
                    Dashboard
                </Link>
            </nav>
        </aside>
        <div class="flex flex-1 flex-col">
            <header class="flex h-16 items-center justify-between border-b border-surface-200 bg-white px-6">
                <slot name="header" />
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-surface-700">{{ page.props.auth.user?.name }}</span>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="rounded-md border border-surface-300 px-3 py-1.5 hover:bg-surface-100"
                    >
                        Log out
                    </Link>
                </div>
            </header>
            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
