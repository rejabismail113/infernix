<script setup lang="ts">
import Icon from '@/Components/Icon.vue';

withDefaults(defineProps<{ title: string; message?: string; icon?: string }>(), { message: undefined, icon: 'document' });
</script>

<template>
    <div class="flex flex-col items-center gap-2 px-4 py-10 text-center">
        <span class="rounded-full bg-surface-100 p-3 text-surface-500"><Icon
            :name="icon"
            :size="22"
        /></span>
        <p class="font-medium text-surface-800">
            {{ title }}
        </p>
        <p
            v-if="message"
            class="max-w-sm text-sm text-surface-500"
        >
            {{ message }}
        </p>
        <slot />
    </div>
</template>
