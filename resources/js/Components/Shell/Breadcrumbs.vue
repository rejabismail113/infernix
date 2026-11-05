<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface Crumb { label: string; href?: string }
defineProps<{ items: Crumb[] }>();
</script>

<template>
    <nav
        v-if="items.length"
        aria-label="Breadcrumb"
    >
        <ol class="flex flex-wrap items-center gap-1 text-sm text-surface-500">
            <li
                v-for="(c, i) in items"
                :key="i"
                class="flex items-center gap-1"
            >
                <Link
                    v-if="c.href && i < items.length - 1"
                    :href="c.href"
                    class="hover:text-surface-800 hover:underline"
                >
                    {{ c.label }}
                </Link>
                <span
                    v-else
                    :aria-current="i === items.length - 1 ? 'page' : undefined"
                    :class="i === items.length - 1 ? 'text-surface-800' : ''"
                >{{ c.label }}</span>
                <span
                    v-if="i < items.length - 1"
                    aria-hidden="true"
                >/</span>
            </li>
        </ol>
    </nav>
</template>
