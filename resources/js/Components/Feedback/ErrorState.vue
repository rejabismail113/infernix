<script setup lang="ts">
import Icon from '@/Components/Icon.vue';

withDefaults(defineProps<{ title?: string; message: string }>(), { title: 'Something went wrong' });
</script>

<template>
    <div
        class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        role="alert"
    >
        <Icon
            name="alert"
            :size="20"
        />
        <div>
            <p class="font-semibold">
                {{ title }}
            </p>
            <p>{{ message }}</p>
            <slot />
        </div>
    </div>
</template>
