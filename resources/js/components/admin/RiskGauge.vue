<script setup lang="ts">
// Semicircle 0–100 gauge with the 4 risk bands (spec v5 §1.3) and a needle. Hand-rolled SVG, no chart library.
import { useInView } from '@/composables/useInView';
import { useTweened } from '@/composables/useTween';
import { RISK_LEVEL_KEYS, riskLevels } from '@/data/riskDisplay';
import { computed, ref } from 'vue';

const props = defineProps<{ value: number | null }>();

// The needle sweeps up from 0 once in view and swings to new values (owner request 2026-11-10).
const el = ref<SVGSVGElement | null>(null);
const shown = useTweened(() => props.value, 1200, useInView(el, 0.6));

const CX = 100;
const CY = 100;
const R = 78;
const BANDS: [number, number][] = [
    [0, 25],
    [25, 50],
    [50, 75],
    [75, 100],
];

function point(v: number, r = R): [number, number] {
    const angle = Math.PI * (1 - v / 100);
    return [CX + r * Math.cos(angle), CY - r * Math.sin(angle)];
}

function arc(from: number, to: number): string {
    const [x1, y1] = point(from);
    const [x2, y2] = point(to);
    return `M ${x1} ${y1} A ${R} ${R} 0 0 1 ${x2} ${y2}`;
}

const needle = computed(() => (shown.value === null ? null : point(Math.max(0, Math.min(100, shown.value)), R - 22)));
</script>

<template>
    <svg ref="el" viewBox="0 0 200 112" class="w-full" role="img" :aria-label="value === null ? 'ไม่มีข้อมูล' : `คะแนน ${value.toFixed(1)} จาก 100`">
        <path
            v-for="(band, i) in BANDS"
            :key="i"
            :d="arc(band[0] + (i ? 0.6 : 0), band[1] - (i < 3 ? 0.6 : 0))"
            fill="none"
            :stroke="riskLevels[RISK_LEVEL_KEYS[i]].color"
            stroke-width="16"
            :opacity="value === null ? 0.25 : 1"
        />
        <template v-if="needle">
            <line :x1="CX" :y1="CY" :x2="needle[0]" :y2="needle[1]" stroke="#334155" stroke-width="4" stroke-linecap="round" />
            <circle :cx="CX" :cy="CY" r="7" fill="#334155" />
        </template>
        <text x="20" y="111" font-size="9" fill="#94a3b8" text-anchor="middle">0</text>
        <text x="180" y="111" font-size="9" fill="#94a3b8" text-anchor="middle">100</text>
    </svg>
</template>
