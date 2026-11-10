// True once the element has scrolled into view, and it stays true. Dashboard animations wait for it, so charts
// below the fold animate when the user reaches them, not unseen on page load (owner 2026-11-10).
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

export function useInView(target: Ref<Element | null>, threshold = 0.25): Ref<boolean> {
    const inView = ref(false);
    let observer: IntersectionObserver | null = null;

    onMounted(() => {
        if (!target.value || typeof IntersectionObserver === 'undefined') {
            inView.value = true;
            return;
        }
        observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    inView.value = true;
                    observer?.disconnect();
                }
            },
            { threshold },
        );
        observer.observe(target.value);
    });
    onBeforeUnmount(() => observer?.disconnect());

    return inView;
}
