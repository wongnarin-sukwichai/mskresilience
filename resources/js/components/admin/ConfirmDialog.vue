<script setup lang="ts">
// Styled replacement for window.confirm() on admin pages — use this instead of the browser's plain alert box.
// Sits above page modals (z-[105], page modals are z-[100], the AdminLayout toast is z-[110]).
withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        message: string;
        confirmLabel?: string;
        processing?: boolean;
    }>(),
    { confirmLabel: 'ยืนยันลบ', processing: false },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();
</script>

<template>
    <Transition name="confirm-fade">
        <div
            v-if="open"
            role="alertdialog"
            aria-modal="true"
            class="fixed inset-0 z-[105] flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="!processing && emit('cancel')"
            @keydown.esc="!processing && emit('cancel')"
        >
            <div class="w-full max-w-sm overflow-hidden rounded-2xl border border-slate-100 bg-white text-center shadow-2xl">
                <div class="px-6 pb-5 pt-7">
                    <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-red-500">
                        <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
                    </span>
                    <h3 class="text-base font-semibold text-slate-800">{{ title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-500">{{ message }}</p>
                </div>
                <div class="flex gap-3 border-t border-slate-100 bg-slate-50/60 px-6 py-4">
                    <button
                        type="button"
                        :disabled="processing"
                        @click="emit('cancel')"
                        class="flex-1 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                    >
                        ยกเลิก
                    </button>
                    <button
                        type="button"
                        :disabled="processing"
                        @click="emit('confirm')"
                        class="flex-1 rounded-xl bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <i v-if="processing" class="fa-solid fa-spinner fa-spin mr-1"></i>
                        {{ processing ? 'กำลังลบ...' : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.confirm-fade-enter-active,
.confirm-fade-leave-active {
    transition: opacity 150ms ease;
}
.confirm-fade-enter-from,
.confirm-fade-leave-to {
    opacity: 0;
}
</style>
