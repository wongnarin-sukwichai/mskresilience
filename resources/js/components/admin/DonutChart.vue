<script setup lang="ts">
import { computed } from 'vue';

interface Segment {
    label: string;
    value: number;
    color: string;
}

const props = defineProps<{
    segments: Segment[];
    centerLabel?: string;
}>();

const SIZE = 168;
const STROKE = 26;
const RADIUS = (SIZE - STROKE) / 2;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const GAP = 3;

const total = computed(() => props.segments.reduce((sum, s) => sum + s.value, 0));

const arcs = computed(() => {
    let cumulativeLength = 0;
    return props.segments.map((segment) => {
        const fraction = total.value > 0 ? segment.value / total.value : 0;
        const rawLength = fraction * CIRCUMFERENCE;
        const visibleLength = Math.max(rawLength - GAP, 0);
        const arc = {
            ...segment,
            percent: (fraction * 100).toFixed(1),
            dasharray: `${visibleLength} ${CIRCUMFERENCE - visibleLength}`,
            dashoffset: -cumulativeLength,
        };
        cumulativeLength += rawLength;
        return arc;
    });
});
</script>

<template>
    <div class="flex flex-col items-center gap-5 sm:flex-row">
        <div class="relative shrink-0" :style="{ width: `${SIZE}px`, height: `${SIZE}px` }">
            <svg :width="SIZE" :height="SIZE" :viewBox="`0 0 ${SIZE} ${SIZE}`" class="-rotate-90">
                <circle :cx="SIZE / 2" :cy="SIZE / 2" :r="RADIUS" fill="none" class="stroke-slate-100" :stroke-width="STROKE" />
                <circle
                    v-for="arc in arcs"
                    :key="arc.label"
                    :cx="SIZE / 2"
                    :cy="SIZE / 2"
                    :r="RADIUS"
                    fill="none"
                    :stroke="arc.color"
                    :stroke-width="STROKE"
                    :stroke-dasharray="arc.dasharray"
                    :stroke-dashoffset="arc.dashoffset"
                    stroke-linecap="butt"
                    class="transition-all"
                >
                    <title>{{ arc.label }}: {{ arc.value }} จุด ({{ arc.percent }}%)</title>
                </circle>
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-bold text-slate-800">{{ total }}</span>
                <span v-if="centerLabel" class="text-xs text-slate-500">{{ centerLabel }}</span>
            </div>
        </div>
        <ul class="w-full flex-1 space-y-2 text-sm">
            <li v-for="arc in arcs" :key="arc.label" class="flex items-center justify-between gap-3">
                <span class="flex items-center gap-2 text-slate-600">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: arc.color }"></span>
                    {{ arc.label }}
                </span>
                <span class="shrink-0 font-medium text-slate-800">
                    {{ arc.value }}
                    <span class="text-xs font-normal text-slate-400">({{ arc.percent }}%)</span>
                </span>
            </li>
        </ul>
    </div>
</template>
