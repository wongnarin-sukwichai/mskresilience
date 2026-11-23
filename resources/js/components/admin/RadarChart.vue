<script setup lang="ts">
import { useInView } from '@/composables/useInView';
import { useTweenedList } from '@/composables/useTween';
import { computed, ref } from 'vue';

// Radar (spider) chart, hand-rolled SVG like the other admin charts (no chart library). Used for the recovery
// readiness R1–R5 (TOR 4.1.1(4), คุณลักษณะ 2.5-1): one polygon per series, values 0–max. Grows from the centre once
// scrolled into view and eases to new values (owner: every chart moves). A null value is drawn at 0 and its dot is
// hollow, so "no data" is visible rather than looking like a real 0.
interface Series {
    label: string;
    color: string;
    values: (number | null)[];
    dashed?: boolean;
}

const props = withDefaults(defineProps<{ axes: { key: string; label: string; hint?: string }[]; series: Series[]; max?: number }>(), { max: 5 });

const SIZE = 300;
const C = SIZE / 2;
const R = 105;

const el = ref<HTMLElement | null>(null);
const flat = computed(() => props.series.flatMap((s) => s.values.map((v) => v ?? 0)));
const shown = useTweenedList(() => flat.value, 1000, useInView(el, 0.4));

const angle = (i: number) => -Math.PI / 2 + (2 * Math.PI * i) / props.axes.length;
const point = (i: number, value: number) => {
    const r = (Math.max(0, Math.min(props.max, value)) / props.max) * R;
    return [C + r * Math.cos(angle(i)), C + r * Math.sin(angle(i))];
};
const ring = (level: number) =>
    props.axes
        .map((_, i) => point(i, level))
        .map((p) => p.join(','))
        .join(' ');

const polygons = computed(() =>
    props.series.map((s, si) => {
        const values = s.values.map((_, i) => shown.value[si * props.axes.length + i] ?? 0);
        return { ...s, points: values.map((v, i) => point(i, v)), path: values.map((v, i) => point(i, v).join(',')).join(' ') };
    }),
);
const labelPos = (i: number) => {
    const r = R + 26;
    return { x: C + r * Math.cos(angle(i)), y: C + r * Math.sin(angle(i)) };
};
const anchor = (i: number) => {
    const x = Math.cos(angle(i));
    return Math.abs(x) < 0.2 ? 'middle' : x > 0 ? 'start' : 'end';
};
</script>

<template>
    <div ref="el" class="w-full">
        <svg :viewBox="`-75 -14 ${SIZE + 150} ${SIZE + 30}`" class="mx-auto w-full max-w-[420px]" role="img" aria-label="แผนภูมิเรดาร์">
            <polygon v-for="l in max" :key="l" :points="ring(l)" class="fill-none stroke-slate-200" stroke-width="1" />
            <line v-for="(a, i) in axes" :key="a.key" :x1="C" :y1="C" :x2="point(i, max)[0]" :y2="point(i, max)[1]" class="stroke-slate-200" />
            <text v-for="l in max" :key="'t' + l" :x="C + 3" :y="C - (l / max) * R + 3" class="fill-slate-300 text-[10px]">{{ l }}</text>
            <g v-for="(p, si) in polygons" :key="p.label">
                <polygon
                    :points="p.path"
                    :fill="p.color"
                    fill-opacity="0.15"
                    :stroke="p.color"
                    stroke-width="2"
                    :stroke-dasharray="p.dashed ? '5 4' : undefined"
                />
                <circle
                    v-for="(pt, i) in p.points"
                    :key="i"
                    :cx="pt[0]"
                    :cy="pt[1]"
                    r="3.5"
                    :fill="series[si].values[i] === null ? '#fff' : p.color"
                    :stroke="p.color"
                    stroke-width="1.5"
                >
                    <title>
                        {{ p.label }} · {{ axes[i].label }}: {{ series[si].values[i] === null ? 'ไม่มีข้อมูล' : series[si].values[i]!.toFixed(1) }}
                    </title>
                </circle>
            </g>
            <text
                v-for="(a, i) in axes"
                :key="'l' + a.key"
                :x="labelPos(i).x"
                :y="labelPos(i).y"
                :text-anchor="anchor(i)"
                dominant-baseline="middle"
                class="fill-slate-600 text-[13px] font-semibold"
            >
                <tspan>{{ a.key }}</tspan>
                <tspan :x="labelPos(i).x" dy="14" class="fill-slate-400 text-[11px] font-normal">{{ a.label }}</tspan>
                <title v-if="a.hint">{{ a.hint }}</title>
            </text>
        </svg>
        <div class="mt-1 flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-slate-600">
            <span v-for="s in series" :key="s.label" class="flex items-center gap-1.5">
                <span
                    class="inline-block h-0.5 w-5"
                    :style="{ background: s.color, borderTop: s.dashed ? `2px dashed ${s.color}` : undefined }"
                ></span>
                {{ s.label }}
            </span>
        </div>
    </div>
</template>
