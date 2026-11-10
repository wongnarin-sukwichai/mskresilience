<script setup lang="ts">
// Fades and lifts its content in once it scrolls into view (dashboard animation rule, owner 2026-11-10).
// `delay` (ms) staggers neighbours. Reduced motion shows it at once.
import { useInView } from '@/composables/useInView';
import { ref } from 'vue';

withDefaults(defineProps<{ delay?: number; tag?: string }>(), { delay: 0, tag: 'div' });

const el = ref<HTMLElement | null>(null);
const inView = useInView(el, 0.15);
</script>

<template>
    <component
        :is="tag"
        ref="el"
        class="transition-all duration-700 ease-out motion-reduce:translate-y-0 motion-reduce:opacity-100 motion-reduce:transition-none"
        :class="inView ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0'"
        :style="{ transitionDelay: `${delay}ms` }"
    >
        <slot />
    </component>
</template>
