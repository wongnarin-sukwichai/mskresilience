<script setup lang="ts">
// A single-score ring (e.g. C_RAW 0–5) that fills once in view and eases to new values (dashboard animation rule).
import { useInView } from '@/composables/useInView';
import { useTweened } from '@/composables/useTween';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ value: number | null; max: number; color?: string; decimals?: number }>(), {
    color: '#10b981',
    decimals: 1,
});

const SIZE = 120;
const STROKE = 12;
const R = (SIZE - STROKE) / 2;
const C = 2 * Math.PI * R;

const el = ref<SVGSVGElement | null>(null);
const shown = useTweened(() => props.value, 1100, useInView(el, 0.5));
const dash = computed(() => `${(Math.max(0, Math.min(1, (shown.value ?? 0) / props.max)) * C).toFixed(2)} ${C.toFixed(2)}`);
</script>

<template>
    <svg
        ref="el"
        :viewBox="`0 0 ${SIZE} ${SIZE}`"
        class="h-full w-full"
        role="img"
        :aria-label="value === null ? 'ไม่มีข้อมูล' : `${value.toFixed(decimals)} จาก ${max}`"
    >
        <circle :cx="SIZE / 2" :cy="SIZE / 2" :r="R" fill="none" stroke="#f1f5f9" :stroke-width="STROKE" />
        <circle
            :cx="SIZE / 2"
            :cy="SIZE / 2"
            :r="R"
            fill="none"
            :stroke="color"
            :stroke-width="STROKE"
            stroke-linecap="round"
            :stroke-dasharray="dash"
            :transform="`rotate(-90 ${SIZE / 2} ${SIZE / 2})`"
            style="transition: stroke 0.5s ease"
        />
        <text :x="SIZE / 2" :y="SIZE / 2 + 2" text-anchor="middle" class="fill-slate-800 text-[26px] font-bold">
            {{ shown === null ? '–' : shown.toFixed(decimals) }}
        </text>
        <text :x="SIZE / 2" :y="SIZE / 2 + 22" text-anchor="middle" class="fill-slate-400 text-[11px]">จาก {{ max }}</text>
    </svg>
</template>
