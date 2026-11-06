<script setup lang="ts">
/**
 * Bar / line chart of money series. Values arrive as decimal strings and are plotted as INTEGER minor units
 * (sen); axis and tooltip labels are formatted back to "RM 1,234.50" — no float money anywhere (rule 1).
 */
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js';
import { computed } from 'vue';
import { Bar, Line } from 'vue-chartjs';
import { formatMyr, toMinorUnits } from '@/lib/money';

ChartJS.register(BarElement, CategoryScale, LinearScale, LineElement, PointElement, Tooltip, Legend);

const props = withDefaults(defineProps<{
    labels: string[];
    series: { label: string; values: string[]; color: string }[];
    kind?: 'bar' | 'line';
    height?: number;
    description: string;
}>(), { kind: 'bar', height: 260 });

const data = computed(() => ({
    labels: props.labels,
    datasets: props.series.map((s) => ({ label: s.label, data: s.values.map((v) => toMinorUnits(v)), backgroundColor: s.color, borderColor: s.color, borderRadius: 4, tension: 0.3 })),
}));
const options = computed(() => ({
    responsive: true, maintainAspectRatio: false, animation: false as const,
    plugins: { legend: { position: 'bottom' as const }, tooltip: { callbacks: { label: (c: { dataset: { label?: string }; raw: unknown }) => `${c.dataset.label ?? ''}: ${formatMyr(Number(c.raw))}` } } },
    scales: { y: { ticks: { callback: (v: string | number) => formatMyr(Math.round(Number(v))).replace('.00', '') } } },
}));
</script>

<template>
    <div
        :style="{ height: `${height}px` }"
        role="img"
        :aria-label="description"
    >
        <Bar
            v-if="kind === 'bar'"
            :data="data"
            :options="options"
        />
        <Line
            v-else
            :data="data"
            :options="options"
        />
    </div>
</template>
