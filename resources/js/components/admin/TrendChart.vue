<script setup lang="ts">
import { computed, ref } from 'vue';

interface Series {
    label: string;
    color: string;
    data: number[];
}

const props = defineProps<{
    categories: string[];
    series: Series[];
}>();

const WIDTH = 600;
const HEIGHT = 220;
const PAD_LEFT = 28;
const PAD_RIGHT = 12;
const PAD_TOP = 12;
const PAD_BOTTOM = 24;

const maxValue = computed(() => {
    const max = Math.max(...props.series.flatMap((s) => s.data), 0);
    const step = max <= 10 ? 5 : max <= 50 ? 10 : 20;
    return Math.ceil((max || step) / step) * step;
});

const yTicks = computed(() => {
    const count = 4;
    return Array.from({ length: count + 1 }, (_, i) => Math.round((maxValue.value / count) * i));
});

function xPos(i: number) {
    const usableWidth = WIDTH - PAD_LEFT - PAD_RIGHT;
    return PAD_LEFT + (props.categories.length > 1 ? (i / (props.categories.length - 1)) * usableWidth : usableWidth / 2);
}

function yPos(v: number) {
    const usableHeight = HEIGHT - PAD_TOP - PAD_BOTTOM;
    return PAD_TOP + usableHeight - (v / maxValue.value) * usableHeight;
}

function linePoints(data: number[]) {
    return data.map((v, i) => `${xPos(i)},${yPos(v)}`).join(' ');
}

const hoverIndex = ref<number | null>(null);

function handleMove(event: MouseEvent) {
    const svg = event.currentTarget as SVGSVGElement;
    const rect = svg.getBoundingClientRect();
    const relX = ((event.clientX - rect.left) / rect.width) * WIDTH;
    const usableWidth = WIDTH - PAD_LEFT - PAD_RIGHT;
    const ratio = Math.min(Math.max((relX - PAD_LEFT) / usableWidth, 0), 1);
    hoverIndex.value = Math.round(ratio * (props.categories.length - 1));
}
</script>

<template>
    <div class="w-full">
        <svg :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" class="w-full cursor-crosshair" @mousemove="handleMove" @mouseleave="hoverIndex = null">
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
            <text v-for="(cat, i) in categories" :key="cat" :x="xPos(i)" :y="HEIGHT - 6" text-anchor="middle" class="fill-slate-400 text-[9px]">
                {{ cat }}
            </text>

            <line
                v-if="hoverIndex !== null"
                :x1="xPos(hoverIndex)"
                :x2="xPos(hoverIndex)"
                :y1="PAD_TOP"
                :y2="HEIGHT - PAD_BOTTOM"
                class="stroke-slate-300"
                stroke-width="1"
                stroke-dasharray="3 3"
            />

            <polyline
                v-for="s in series"
                :key="s.label"
                :points="linePoints(s.data)"
                fill="none"
                :stroke="s.color"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <g v-for="s in series" :key="s.label + '-dots'">
                <circle
                    v-for="(v, i) in s.data"
                    v-show="i === s.data.length - 1 || i === hoverIndex"
                    :key="i"
                    :cx="xPos(i)"
                    :cy="yPos(v)"
                    r="4"
                    :fill="s.color"
                    stroke="white"
                    stroke-width="2"
                />
            </g>
        </svg>

        <div v-if="hoverIndex !== null" class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg bg-slate-800 px-3 py-2 text-xs text-white">
            <span class="font-semibold">{{ categories[hoverIndex] }}</span>
            <span v-for="s in series" :key="s.label" class="flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: s.color }"></span>
                {{ s.label }}: {{ s.data[hoverIndex] }}
            </span>
        </div>

        <div class="mt-3 flex flex-wrap gap-4 text-xs text-slate-500">
            <span v-for="s in series" :key="s.label" class="flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: s.color }"></span>
                {{ s.label }}
            </span>
        </div>
    </div>
</template>
