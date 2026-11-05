<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
</script>

<template>
    <div
        v-if="page.props.flash?.success"
        class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800"
        role="status"
    >
        {{ page.props.flash.success }}
    </div>
    <div
        v-if="page.props.flash?.error"
        class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800"
        role="alert"
    >
        {{ page.props.flash.error }}
    </div>
</template>
