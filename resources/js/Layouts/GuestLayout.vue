<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-surface-50 px-4">
        <div class="mb-8 text-center">
            <img
                src="/images/infernix-logo.svg"
                :alt="page.props.app.company.name"
                class="mx-auto h-20"
            >
            <p class="mt-2 text-sm text-surface-500">
                {{ page.props.app.company.tagline }}
            </p>
        </div>
        <div class="w-full max-w-md rounded-xl border border-surface-200 bg-white p-8 shadow-sm">
            <slot />
        </div>
    </div>
</template>
