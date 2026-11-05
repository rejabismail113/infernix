<script setup lang="ts">
withDefaults(defineProps<{ title?: string; description?: string }>(), { title: undefined, description: undefined });
</script>

<template>
    <section class="rounded-lg border border-surface-200 bg-white">
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-100 px-5 py-3"
        >
            <div>
                <h2
                    v-if="title"
                    class="font-semibold text-surface-900"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="text-sm text-surface-500"
                >
                    {{ description }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div class="p-5">
            <slot />
        </div>
    </section>
</template>
