<script setup lang="ts">
// A horizontal score bar that grows from 0 once in view and eases to new values (dashboard animation rule,
// owner 2026-11-10). Null = no data: an empty track.
import { useInView } from '@/composables/useInView';
import { useTweened } from '@/composables/useTween';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ value: number | null; max: number; color?: string; height?: string }>(), {
    color: '#10b981',
    height: 'h-2',
});

const el = ref<HTMLElement | null>(null);
const shown = useTweened(() => props.value, 900, useInView(el, 0.1));
const width = computed(() => `${Math.max(0, Math.min(100, ((shown.value ?? 0) / props.max) * 100))}%`);
</script>

<template>
    <div ref="el" class="overflow-hidden rounded-full bg-slate-100" :class="height">
        <div class="h-full rounded-full" :style="{ width, background: color, transition: 'background-color 0.5s ease' }"></div>
    </div>
</template>
