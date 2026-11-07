<script setup lang="ts">
/**
 * Searchable dropdown (combobox) for long option lists inside forms, e.g. picking a client. Type to filter,
 * arrow keys to move, Enter to choose, Esc to close. `pinned` options (e.g. "+ Add new client") always stay at the
 * bottom of the list whatever the search. For server-searched lists use <PickerModal> instead.
 */
import { computed, nextTick, ref, watch } from 'vue';

export interface SearchOption { value: string | number; label: string; meta?: string | null }

const props = withDefaults(defineProps<{
    id: string;
    options: SearchOption[];
    pinned?: SearchOption[];
    placeholder?: string;
    invalid?: boolean;
    emptyText?: string;
}>(), { pinned: () => [], placeholder: 'Search…', invalid: false, emptyText: 'No matches' });

const model = defineModel<string | number | ''>({ default: '' });
const open = ref(false);
const term = ref('');
const active = ref(0);
const list = ref<HTMLElement | null>(null);

const all = computed(() => [...props.options, ...props.pinned]);
const selected = computed(() => all.value.find((o) => o.value === model.value) ?? null);
const filtered = computed(() => {
    const q = term.value.trim().toLowerCase();
    const hits = q === '' ? props.options : props.options.filter((o) => `${o.label} ${o.meta ?? ''}`.toLowerCase().includes(q));
    return [...hits, ...props.pinned];
});

watch(open, (v) => {
    if (v) {
        term.value = '';
        active.value = Math.max(0, filtered.value.findIndex((o) => o.value === model.value));
    }
});
watch(term, () => { active.value = 0; });

function choose(o: SearchOption) {
    model.value = o.value;
    open.value = false;
}
function scrollActive() {
    void nextTick(() => list.value?.querySelector<HTMLElement>(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }));
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open.value) { open.value = true; return; }
        const n = filtered.value.length;
        if (n === 0) return;
        active.value = (active.value + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
        scrollActive();
    } else if (e.key === 'Enter' && open.value) {
        e.preventDefault();
        const o = filtered.value[active.value];
        if (o) choose(o);
    } else if (e.key === 'Escape' && open.value) {
        e.stopPropagation();
        open.value = false;
    }
}
function onBlur(e: FocusEvent) {
    if (!(e.relatedTarget instanceof Node && list.value?.contains(e.relatedTarget))) open.value = false;
}
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            :value="open ? term : (selected?.label ?? '')"
            type="text"
            role="combobox"
            autocomplete="off"
            class="w-full !pr-9"
            :placeholder="selected ? selected.label : placeholder"
            :aria-expanded="open"
            :aria-controls="`${id}-list`"
            :aria-activedescendant="open && filtered[active] ? `${id}-opt-${active}` : undefined"
            :aria-invalid="invalid ? 'true' : undefined"
            @focus="open = true"
            @click="open = true"
            @input="term = ($event.target as HTMLInputElement).value; open = true"
            @keydown="onKey"
            @blur="onBlur"
        >
        <svg
            class="pointer-events-none absolute right-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-surface-500"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            aria-hidden="true"
        ><path d="m5 8 5 5 5-5" /></svg>
        <ul
            v-show="open"
            :id="`${id}-list`"
            ref="list"
            role="listbox"
            tabindex="-1"
            class="absolute left-0 right-0 z-30 mt-1 max-h-64 overflow-auto rounded-xl border border-surface-200 bg-white/95 p-1 shadow-xl backdrop-blur"
        >
            <li
                v-for="(o, i) in filtered"
                :id="`${id}-opt-${i}`"
                :key="`${o.value}`"
                role="option"
                :data-index="i"
                :aria-selected="o.value === model"
                class="cursor-pointer rounded-lg px-3 py-2 text-sm"
                :class="[
                    i === active ? 'bg-brand-50 text-brand-900' : 'text-surface-800',
                    pinned.some((p) => p.value === o.value) ? 'font-medium text-brand-700' : '',
                    i === filtered.length - pinned.length && pinned.length > 0 && filtered.length > pinned.length ? 'mt-1 border-t border-surface-100' : '',
                ]"
                @mousedown.prevent="choose(o)"
                @mousemove="active = i"
            >
                {{ o.label }}
                <span
                    v-if="o.meta"
                    class="block text-xs text-surface-500"
                >{{ o.meta }}</span>
            </li>
            <li
                v-if="filtered.length === pinned.length && term"
                class="px-3 py-2 text-sm text-surface-500"
            >
                {{ emptyText }}
            </li>
        </ul>
    </div>
</template>
