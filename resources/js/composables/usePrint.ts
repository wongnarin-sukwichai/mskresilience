import { nextTick, ref } from 'vue';

// Print / "บันทึก PDF" (TOR 4.4-4, owner 2026-11-23 option ก: the browser's own print, so Thai text and the charts
// come out exactly as on screen). The charts animate only once scrolled into view, so a chart below the fold would
// print empty or half-grown. While `printing` is true every tween jumps to its final value and every useInView is
// true (useTween.ts / useInView.ts watch it).
export const printing = ref(false);

if (typeof window !== 'undefined') {
    // Ctrl+P or the browser menu: best effort (the browser may lay out the page before Vue re-renders).
    window.addEventListener('beforeprint', () => (printing.value = true));
    window.addEventListener('afterprint', () => (printing.value = false));
}

/** The "บันทึก PDF" buttons: settle every chart first, then open the print dialog (choose "Save as PDF"). */
export async function printPage(): Promise<void> {
    printing.value = true;
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 350));
    window.print();
}
