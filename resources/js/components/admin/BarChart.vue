<script setup lang="ts">
import { useInView } from '@/composables/useInView';
import { useTweenedList } from '@/composables/useTween';
import { computed, ref } from 'vue';

interface Bar {
    label: string;
    value: number;
    color: string;
}

const props = withDefaults(
    defineProps<{
        bars: Bar[];
        max?: number;
        /** Decimals for the value labels (also used while animating). */
        decimals?: number;
        /** Index of the bar to emphasise (the others fade back); null = all equal. */
        highlight?: number | null;
    }>(),
    { max: undefined, decimals: 0, highlight: null },
);

// Bars grow from the baseline once scrolled into view and ease to new values (owner request 2026-11-10).
const el = ref<HTMLElement | null>(null);
const shown = useTweenedList(() => props.bars.map((b) => b.value), 1000, useInView(el, 0.6));
const shownValue = (i: number) => shown.value[i] ?? 0;

const WIDTH = 600;
const HEIGHT = 220;
const PAD_LEFT = 28;
const PAD_RIGHT = 12;
const PAD_TOP = 20;
const PAD_BOTTOM = 24;
const GAP_RATIO = 0.4;

const maxValue = computed(() => {
    if (props.max) return props.max;
    const max = Math.max(...props.bars.map((b) => b.value), 0);
    const step = max <= 10 ? 5 : max <= 50 ? 10 : 20;
    return Math.ceil((max || step) / step) * step;
});

const yTicks = computed(() => {
    const count = 4;
    return Array.from({ length: count + 1 }, (_, i) => Math.round((maxValue.value / count) * i));
});

function yPos(v: number) {
    const usableHeight = HEIGHT - PAD_TOP - PAD_BOTTOM;
    return PAD_TOP + usableHeight - (v / maxValue.value) * usableHeight;
}

const bandWidth = computed(() => (WIDTH - PAD_LEFT - PAD_RIGHT) / props.bars.length);

function barX(i: number) {
    return PAD_LEFT + i * bandWidth.value + (bandWidth.value * GAP_RATIO) / 2;
}

const barWidth = computed(() => bandWidth.value * (1 - GAP_RATIO));

function xCenter(i: number) {
    return PAD_LEFT + i * bandWidth.value + bandWidth.value / 2;
}
</script>

<template>
    <div ref="el" class="w-full">
        <svg :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" class="w-full">
            <line
                v-for="tick in yTicks"
                :key="'grid-' + tick"
                :x1="PAD_LEFT"
                :x2="WIDTH - PAD_RIGHT"
                :y1="yPos(tick)"
                :y2="yPos(tick)"
                class="stroke-slate-100"
                stroke-width="1"
            />
            <text
                v-for="tick in yTicks"
                :key="'label-' + tick"
                :x="PAD_LEFT - 8"
                :y="yPos(tick) + 3"
                text-anchor="end"
                class="fill-slate-400 text-[9px]"
            >
                {{ tick }}
            </text>

            <g
                v-for="(bar, i) in bars"
                :key="bar.label"
                :opacity="highlight === null || highlight === i ? 1 : 0.25"
                style="transition: opacity 0.5s ease"
            >
                <rect
                    :x="barX(i)"
                    :y="yPos(shownValue(i))"
                    :width="barWidth"
                    :height="Math.max(HEIGHT - PAD_BOTTOM - yPos(shownValue(i)), 0)"
                    :fill="bar.color"
                    rx="4"
                    :stroke="highlight === i ? '#334155' : 'transparent'"
                    stroke-width="2"
                    style="
                        transition:
                            fill 0.6s ease,
                            stroke 0.5s ease;
                    "
                >
                    <title>{{ bar.label }}: {{ bar.value }}</title>
                </rect>
                <text :x="xCenter(i)" :y="yPos(shownValue(i)) - 6" text-anchor="middle" class="fill-slate-600 text-[10px] font-semibold">
                    {{ shownValue(i).toFixed(decimals) }}
                </text>
                <text
                    :x="xCenter(i)"
                    :y="HEIGHT - 6"
                    text-anchor="middle"
                    class="text-[9px]"
                    :class="highlight === i ? 'fill-slate-800 font-bold' : 'fill-slate-400'"
                >
                    {{ bar.label }}
                </text>
            </g>
        </svg>
    </div>
</template>
