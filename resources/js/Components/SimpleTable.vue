<script setup lang="ts" generic="T extends object">
/** Temporary plain table; Prompt 1c replaces it with <DataTable>. */
defineProps<{ columns: { key: string; label: string }[]; rows: T[] }>();
const get = (row: T, key: string): unknown => (row as Record<string, unknown>)[key];
const rowKey = (row: T, i: number): string | number => (get(row, 'id') as string | number | undefined) ?? i;
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-surface-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-surface-100 text-left text-xs uppercase text-surface-500">
                <tr>
                    <th
                        v-for="c in columns"
                        :key="c.key"
                        class="px-3 py-2 font-medium"
                    >
                        {{ c.label }}
                    </th>
                    <th
                        v-if="$slots.actions"
                        class="px-3 py-2"
                    />
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(r, i) in rows"
                    :key="rowKey(r, i)"
                    class="border-t border-surface-100"
                >
                    <td
                        v-for="c in columns"
                        :key="c.key"
                        class="px-3 py-2 align-top"
                    >
                        <slot
                            :name="`cell-${c.key}`"
                            :row="r"
                        >
                            {{ Array.isArray(get(r, c.key)) ? (get(r, c.key) as unknown[]).join(', ') : (get(r, c.key) ?? '—') }}
                        </slot>
                    </td>
                    <td
                        v-if="$slots.actions"
                        class="px-3 py-2 text-right"
                    >
                        <slot
                            name="actions"
                            :row="r"
                        />
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td
                        :colspan="columns.length + 1"
                        class="px-3 py-6 text-center text-surface-400"
                    >
                        No records.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
