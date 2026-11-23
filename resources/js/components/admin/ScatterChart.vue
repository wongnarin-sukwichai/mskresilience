<script setup lang="ts">
import { useInView } from '@/composables/useInView';
import { useTweenedList } from '@/composables/useTween';
import { computed, ref } from 'vue';

// Scatter plot, hand-rolled SVG: one dot per community. Used for HEVC × damage on page 5 (TOR 4.1.1(5)-2) and
// HEVC × budget on the executive page (4.1.1(3)-1). Dots slide in from the origin once in view and ease to new
// positions (owner: every chart moves). `splitX`/`splitY` draw dashed quadrant lines; `quadrantLabels` names the
// corners. Clicking a dot emits `select`.
export interface ScatterPoint {
    id: string;
    label: string;
    x: number;
    y: number;
    color: string;
    /** Drawn larger with a ring (e.g. the selected community). */
    emphasis?: boolean;
}

const props = withDefaults(
    defineProps<{
        points: ScatterPoint[];
        xLabel: string;
        yLabel: string;
        xMax?: number;
        yMax?: number;
        splitX?: number | null;
        splitY?: number | null;
        quadrantLabels?: { topRight?: string; topLeft?: string; bottomRight?: string; bottomLeft?: string };
        formatX?: (v: number) => string;
        formatY?: (v: number) => string;
    }>(),
    {
        xMax: undefined,
        yMax: undefined,
        splitX: null,
        splitY: null,
        quadrantLabels: () => ({}),
        formatX: (v: number) => v.toLocaleString('th-TH', { maximumFractionDigits: 1 }),
        formatY: (v: number) => v.toLocaleString('th-TH', { maximumFractionDigits: 0 }),
    },
);
const emit = defineEmits<{ select: [id: string] }>();

const W = 600;
const H = 340;
const PAD = { left: 70, right: 16, top: 16, bottom: 40 };

const nice = (m: number) => {
    if (m <= 0) return 1;
    const mag = 10 ** Math.floor(Math.log10(m));
    return Math.ceil(m / mag) * mag;
};
const xTop = computed(() => props.xMax ?? nice(Math.max(0, ...props.points.map((p) => p.x)) * 1.05));
const yTop = computed(() => props.yMax ?? nice(Math.max(0, ...props.points.map((p) => p.y)) * 1.05));
const sx = (v: number) => PAD.left + (W - PAD.left - PAD.right) * (Math.min(v, xTop.value) / xTop.value);
const sy = (v: number) => PAD.top + (H - PAD.top - PAD.bottom) * (1 - Math.min(v, yTop.value) / yTop.value);

const el = ref<HTMLElement | null>(null);
const shown = useTweenedList(() => props.points.flatMap((p) => [p.x, p.y]), 1100, useInView(el, 0.4));
const placed = computed(() => props.points.map((p, i) => ({ ...p, cx: sx(shown.value[i * 2] ?? 0), cy: sy(shown.value[i * 2 + 1] ?? 0) })));
const xTicks = computed(() => Array.from({ length: 5 }, (_, i) => (xTop.value / 4) * i));
const yTicks = computed(() => Array.from({ length: 5 }, (_, i) => (yTop.value / 4) * i));
const hover = ref<string | null>(null);
const hovered = computed(() => placed.value.find((p) => p.id === hover.value) ?? null);
// Tablets have no hover (TOR 4.1.3, phase 5): with touch or pen, the first tap on a point shows its label and a
// second tap on the same point opens it. A mouse keeps hover + one click.
const touchInput = ref(false);
function onPointerDown(e: PointerEvent) {
    touchInput.value = e.pointerType !== 'mouse';
}
function onEnter(id: string, e: PointerEvent) {
    if (e.pointerType === 'mouse') hover.value = id;
}
function onLeave(e: PointerEvent) {
    if (e.pointerType === 'mouse') hover.value = null;
}
function onPick(id: string) {
    if (touchInput.value && hover.value !== id) {
        hover.value = id;
        return;
    }
    emit('select', id);
}
</script>

<template>
    <div ref="el" class="relative w-full">
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="w-full"
            role="img"
            @pointerdown="onPointerDown"
            @click.self="hover = null"
            :aria-label="`${xLabel} เทียบกับ ${yLabel}`"
        >
            <g v-for="t in yTicks" :key="'y' + t">
                <line :x1="PAD.left" :x2="W - PAD.right" :y1="sy(t)" :y2="sy(t)" class="stroke-slate-100" />
                <text :x="PAD.left - 6" :y="sy(t) + 3" text-anchor="end" class="fill-slate-400 text-[9px]">{{ formatY(t) }}</text>
            </g>
            <g v-for="t in xTicks" :key="'x' + t">
                <text :x="sx(t)" :y="H - PAD.bottom + 14" text-anchor="middle" class="fill-slate-400 text-[9px]">{{ formatX(t) }}</text>
            </g>
            <line :x1="PAD.left" :x2="W - PAD.right" :y1="H - PAD.bottom" :y2="H - PAD.bottom" class="stroke-slate-300" />
            <line :x1="PAD.left" :x2="PAD.left" :y1="PAD.top" :y2="H - PAD.bottom" class="stroke-slate-300" />
            <text :x="(W + PAD.left) / 2" :y="H - 6" text-anchor="middle" class="fill-slate-600 text-[11px] font-medium">{{ xLabel }}</text>
            <text
                :x="20"
                :y="(H - PAD.bottom) / 2"
                text-anchor="middle"
                :transform="`rotate(-90 20 ${(H - PAD.bottom) / 2})`"
                class="fill-slate-600 text-[11px] font-medium"
            >
                {{ yLabel }}
            </text>

            <line
                v-if="splitX !== null"
                :x1="sx(splitX)"
                :x2="sx(splitX)"
                :y1="PAD.top"
                :y2="H - PAD.bottom"
                class="stroke-slate-400"
                stroke-dasharray="5 4"
            />
            <line
                v-if="splitY !== null"
                :x1="PAD.left"
                :x2="W - PAD.right"
                :y1="sy(splitY)"
                :y2="sy(splitY)"
                class="stroke-slate-400"
                stroke-dasharray="5 4"
            />
            <text v-if="quadrantLabels.topRight" :x="W - PAD.right - 4" :y="PAD.top + 12" text-anchor="end" class="fill-slate-400 text-[10px]">
                {{ quadrantLabels.topRight }}
            </text>
            <text v-if="quadrantLabels.topLeft" :x="PAD.left + 6" :y="PAD.top + 12" class="fill-slate-400 text-[10px]">
                {{ quadrantLabels.topLeft }}
            </text>
            <text
                v-if="quadrantLabels.bottomRight"
                :x="W - PAD.right - 4"
                :y="H - PAD.bottom - 6"
                text-anchor="end"
                class="fill-red-400 text-[10px] font-medium"
            >
                {{ quadrantLabels.bottomRight }}
            </text>
            <text v-if="quadrantLabels.bottomLeft" :x="PAD.left + 6" :y="H - PAD.bottom - 6" class="fill-slate-400 text-[10px]">
                {{ quadrantLabels.bottomLeft }}
            </text>

            <g
                v-for="p in placed"
                :key="p.id"
                class="cursor-pointer"
                @pointerenter="onEnter(p.id, $event)"
                @pointerleave="onLeave"
                @click="onPick(p.id)"
            >
                <circle :cx="p.cx" :cy="p.cy" :r="p.emphasis ? 9 : 6.5" :fill="p.color" fill-opacity="0.85" stroke="#fff" stroke-width="1.5" />
                <circle v-if="p.emphasis" :cx="p.cx" :cy="p.cy" r="13" fill="none" :stroke="p.color" stroke-width="2" />
            </g>
        </svg>
        <div
            v-if="hovered"
            class="pointer-events-none absolute rounded-lg bg-slate-900/90 px-2.5 py-1.5 text-[11px] text-white shadow"
            :style="{ left: `${(hovered.cx / W) * 100}%`, top: `${(hovered.cy / H) * 100}%`, transform: 'translate(-50%, -130%)' }"
        >
            <p class="font-semibold">{{ hovered.label }}</p>
            <p>{{ xLabel }}: {{ formatX(hovered.x) }}</p>
            <p>{{ yLabel }}: {{ formatY(hovered.y) }}</p>
            <p v-if="touchInput" class="mt-0.5 text-slate-300">แตะอีกครั้งเพื่อเปิด</p>
        </div>
    </div>
</template>
