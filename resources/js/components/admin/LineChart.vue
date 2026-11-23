<script setup lang="ts">
import { useInView } from '@/composables/useInView';
import { useTweenedList } from '@/composables/useTween';
import { computed, ref } from 'vue';

// Line chart for trends (HEVC per fiscal year, losses per month), hand-rolled SVG. Lines rise from the baseline once
// scrolled into view and ease to new values (owner: every chart moves). A null value breaks the line. `bands` draws
// the risk-level background (0–24/25–49/50–74/75–100) for HEVC; `hollow` marks points to draw as open circles
// (e.g. a year with no new data).
interface Series {
    label: string;
    color: string;
    values: (number | null)[];
    dashed?: boolean;
    hollow?: boolean[];
}

const props = withDefaults(
    defineProps<{
        categories: string[];
        series: Series[];
        max?: number;
        bands?: { from: number; to: number; color: string }[];
        format?: (value: number) => string;
        height?: number;
        /** viewBox width: use ~320 in a narrow panel so the labels stay readable. */
        width?: number;
    }>(),
    { max: undefined, bands: () => [], format: (v: number) => v.toLocaleString('th-TH', { maximumFractionDigits: 1 }), height: 220, width: 600 },
);

const WIDTH = props.width;
const PAD = { left: 46, right: 14, top: 14, bottom: 26 };
const H = computed(() => props.height);

const el = ref<HTMLElement | null>(null);
const flat = computed(() => props.series.flatMap((s) => s.values));
const shown = useTweenedList(() => flat.value, 1000, useInView(el, 0.5));

const top = computed(() => {
    if (props.max !== undefined) return props.max;
    const m = Math.max(0, ...flat.value.filter((v): v is number => v !== null));
    if (m === 0) return 1;
    const mag = 10 ** Math.floor(Math.log10(m));
    return Math.ceil(m / mag) * mag;
});
const ticks = computed(() => Array.from({ length: 5 }, (_, i) => (top.value / 4) * i));
const x = (i: number) =>
    PAD.left +
    (props.categories.length > 1 ? (i / (props.categories.length - 1)) * (WIDTH - PAD.left - PAD.right) : (WIDTH - PAD.left - PAD.right) / 2);
const y = (v: number) => PAD.top + (H.value - PAD.top - PAD.bottom) * (1 - Math.min(v, top.value) / top.value);

const lines = computed(() =>
    props.series.map((s, si) => {
        const n = props.categories.length;
        const values = s.values.map((v, i) => (v === null ? null : (shown.value[si * n + i] ?? 0)));
        // Split into segments at nulls.
        const segments: string[] = [];
        let current: string[] = [];
        values.forEach((v, i) => {
            if (v === null) {
                if (current.length) segments.push(current.join(' '));
                current = [];
            } else current.push(`${x(i)},${y(v)}`);
        });
        if (current.length) segments.push(current.join(' '));
        return { ...s, segments, points: values };
    }),
);

const hover = ref<number | null>(null);
// Pointer events, so a tap on a tablet shows the values too (TOR 4.1.3); only a mouse clears it on leave.
function onMove(e: PointerEvent) {
    const rect = (e.currentTarget as SVGElement).getBoundingClientRect();
    const rel = (((e.clientX - rect.left) / rect.width) * WIDTH - PAD.left) / (WIDTH - PAD.left - PAD.right);
    hover.value = Math.max(0, Math.min(props.categories.length - 1, Math.round(rel * (props.categories.length - 1))));
}
// Show at most ~12 x labels.
const labelEvery = computed(() => Math.max(1, Math.ceil(props.categories.length / 12)));
</script>

<template>
    <div ref="el" class="relative w-full">
        <svg
            :viewBox="`0 0 ${WIDTH} ${H}`"
            class="w-full touch-pan-y"
            @pointermove="onMove"
            @pointerdown="onMove"
            @pointerleave="(e) => e.pointerType === 'mouse' && (hover = null)"
        >
            <rect
                v-for="b in bands"
                :key="b.from"
                :x="PAD.left"
                :width="WIDTH - PAD.left - PAD.right"
                :y="y(Math.min(b.to, top))"
                :height="Math.max(0, y(b.from) - y(Math.min(b.to, top)))"
                :fill="b.color"
                fill-opacity="0.08"
            />
            <g v-for="t in ticks" :key="t">
                <line :x1="PAD.left" :x2="WIDTH - PAD.right" :y1="y(t)" :y2="y(t)" class="stroke-slate-100" />
                <text :x="PAD.left - 6" :y="y(t) + 3" text-anchor="end" class="fill-slate-400 text-[9px]">{{ format(t) }}</text>
            </g>
            <text
                v-for="(c, i) in categories"
                v-show="(categories.length - 1 - i) % labelEvery === 0"
                :key="c"
                :x="x(i)"
                :y="H - 8"
                :text-anchor="categories.length > 1 && i === 0 ? 'start' : i === categories.length - 1 && categories.length > 1 ? 'end' : 'middle'"
                class="fill-slate-500 text-[10px]"
            >
                {{ c }}
            </text>
            <line
                v-if="hover !== null"
                :x1="x(hover)"
                :x2="x(hover)"
                :y1="PAD.top"
                :y2="H - PAD.bottom"
                class="stroke-slate-300"
                stroke-dasharray="3 3"
            />
            <g v-for="(l, si) in lines" :key="l.label">
                <polyline
                    v-for="(seg, k) in l.segments"
                    :key="k"
                    :points="seg"
                    fill="none"
                    :stroke="l.color"
                    stroke-width="2.5"
                    stroke-linejoin="round"
                    :stroke-dasharray="l.dashed ? '6 4' : undefined"
                />
                <template v-for="(v, i) in l.points" :key="i">
                    <circle
                        v-if="v !== null"
                        :cx="x(i)"
                        :cy="y(v)"
                        :r="hover === i ? 5 : 3.5"
                        :fill="series[si].hollow?.[i] ? '#fff' : l.color"
                        :stroke="l.color"
                        stroke-width="2"
                    />
                </template>
            </g>
        </svg>
        <div
            v-if="hover !== null"
            class="pointer-events-none absolute top-2 rounded-lg bg-slate-900/90 px-2.5 py-1.5 text-[11px] text-white shadow"
            :style="{ left: `${(x(hover) / WIDTH) * 100}%`, transform: hover > categories.length / 2 ? 'translateX(-105%)' : 'translateX(5%)' }"
        >
            <p class="font-semibold">{{ categories[hover] }}</p>
            <p v-for="s in series" :key="s.label">
                <span class="mr-1 inline-block h-2 w-2 rounded-full" :style="{ background: s.color }"></span>{{ s.label }}:
                {{ s.values[hover] === null ? '–' : format(s.values[hover]!) }}
                <span v-if="s.hollow?.[hover]" class="text-slate-300">(ไม่มีข้อมูลใหม่)</span>
            </p>
        </div>
        <div v-if="series.length > 1" class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
            <span v-for="s in series" :key="s.label" class="flex items-center gap-1.5">
                <span class="inline-block h-2 w-2 rounded-full" :style="{ background: s.color }"></span>{{ s.label }}
            </span>
        </div>
    </div>
</template>
