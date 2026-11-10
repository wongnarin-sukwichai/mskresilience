<script setup lang="ts">
// A number that counts up once it scrolls into view and eases to new values (composables/useTween.ts).
import { useInView } from '@/composables/useInView';
import { useTweened } from '@/composables/useTween';
import { ref } from 'vue';

const props = withDefaults(
    defineProps<{
        value: number | null | undefined;
        /** Formats the in-between values too, so pass the same formatter as the final number. */
        format?: (value: number) => string;
        empty?: string;
    }>(),
    { format: (v: number) => Math.round(v).toLocaleString('th-TH'), empty: '–' },
);

const el = ref<HTMLElement | null>(null);
const shown = useTweened(() => props.value ?? null, undefined, useInView(el));
</script>

<template>
    <span ref="el" class="tabular-nums">{{ shown === null ? empty : format(shown) }}</span>
</template>
