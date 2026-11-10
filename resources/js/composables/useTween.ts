// Smoothly animates numbers for the dashboard (owner request 2026-11-10: every chart/number that changes should
// move). Values grow from 0 the first time they become active (pass `active` from useInView so a chart below the
// fold waits until it's scrolled into view); later changes ease from the shown value to the new one. It works for
// anything driven by a number: SVG geometry, text, CSS widths. Users with "reduce motion" get the final value at once.
import { computed, onBeforeUnmount, ref, watch, type ComputedRef, type Ref } from 'vue';

const DEFAULT_DURATION = 900;

function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

const easeOutCubic = (t: number) => 1 - Math.pow(1 - t, 3);

/**
 * A tweened copy of a list of numbers. Null entries stay null (no data) and aren't animated. While `active` is
 * false the values stay at 0.
 */
export function useTweenedList(
    source: () => (number | null)[],
    duration = DEFAULT_DURATION,
    active: Ref<boolean> = ref(true),
): Ref<(number | null)[]> {
    const shown = ref<(number | null)[]>(source().map((v) => (v === null ? null : 0)));
    let frame = 0;

    const animate = (target: (number | null)[]) => {
        cancelAnimationFrame(frame);
        if (!active.value) {
            shown.value = target.map((v) => (v === null ? null : 0));
            return;
        }
        if (prefersReducedMotion()) {
            shown.value = [...target];
            return;
        }
        const from = target.map((_, i) => shown.value[i] ?? 0);
        const start = performance.now();
        const step = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const k = easeOutCubic(t);
            shown.value = target.map((to, i) => (to === null ? null : from[i] + (to - from[i]) * k));
            if (t < 1) frame = requestAnimationFrame(step);
        };
        frame = requestAnimationFrame(step);
    };

    watch([source, active], () => animate(source()), { immediate: true, deep: true });
    onBeforeUnmount(() => cancelAnimationFrame(frame));

    return shown;
}

/**
 * A tweened copy of one number (null = no data).
 */
export function useTweened(source: () => number | null, duration = DEFAULT_DURATION, active?: Ref<boolean>): ComputedRef<number | null> {
    const list = useTweenedList(() => [source()], duration, active);
    return computed(() => list.value[0]);
}
