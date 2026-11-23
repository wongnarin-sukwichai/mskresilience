<script setup lang="ts">
import { useInView } from '@/composables/useInView';
import { useTweened } from '@/composables/useTween';
import { computed, ref } from 'vue';

// Half-circle gauge for the MCR2030 evidence coverage 0–max (TOR 4.1.1(3)-2, คุณลักษณะ 2.4-2). Unlike RiskGauge
// (risk bands, high = bad) this one fills one color, high = more evidence. The arc sweeps up once in view (owner:
// every chart moves). `ceiling` marks the highest value reachable today (E1's missing column), as a tick.
const props = withDefaults(defineProps<{ value: number | null; max?: number; ceiling?: number | null }>(), { max: 10, ceiling: null });

const el = ref<SVGSVGElement | null>(null);
const shown = useTweened(() => props.value, 1200, useInView(el, 0.5));

const CX = 100;
const CY = 100;
const R = 78;
const point = (v: number, r = R): [number, number] => {
    const a = Math.PI * (1 - Math.max(0, Math.min(props.max, v)) / props.max);
    return [CX + r * Math.cos(a), CY - r * Math.sin(a)];
};
const arc = (to: number) => {
    const [x0, y0] = point(0);
    const [x1, y1] = point(to);
    return `M ${x0} ${y0} A ${R} ${R} 0 0 1 ${x1} ${y1}`;
};
const color = computed(() => {
    const ratio = (shown.value ?? 0) / props.max;
    return ratio >= 0.8 ? '#059669' : ratio >= 0.5 ? '#0d9488' : ratio >= 0.3 ? '#f59e0b' : '#ef4444';
});
</script>

<template>
    <svg ref="el" viewBox="0 0 200 118" class="w-full max-w-[260px]" role="img" :aria-label="`ความครอบคลุม ${value ?? '–'} จาก ${max}`">
        <path :d="arc(max)" fill="none" class="stroke-slate-100" stroke-width="16" stroke-linecap="round" />
        <path v-if="(shown ?? 0) > 0" :d="arc(shown ?? 0)" fill="none" :stroke="color" stroke-width="16" stroke-linecap="round" />
        <g v-if="ceiling !== null && ceiling < max">
            <line
                :x1="point(ceiling, R - 12)[0]"
                :y1="point(ceiling, R - 12)[1]"
                :x2="point(ceiling, R + 12)[0]"
                :y2="point(ceiling, R + 12)[1]"
                class="stroke-slate-400"
                stroke-width="2"
            />
            <title>สูงสุดที่ทำได้ตอนนี้ {{ ceiling }} (E1 ยังขาดช่องข้อมูลหน่วยงานรับผิดชอบ)</title>
        </g>
        <text :x="CX" :y="CY - 6" text-anchor="middle" class="fill-slate-800 text-[30px] font-bold">
            {{ shown === null ? '–' : shown.toFixed(1) }}
        </text>
        <text :x="CX" :y="CY + 12" text-anchor="middle" class="fill-slate-400 text-[10px]">จาก {{ max }}</text>
        <text :x="point(0)[0]" :y="CY + 14" text-anchor="middle" class="fill-slate-400 text-[9px]">0</text>
        <text :x="point(max)[0]" :y="CY + 14" text-anchor="middle" class="fill-slate-400 text-[9px]">{{ max }}</text>
    </svg>
</template>
