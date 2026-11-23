<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// Previous/next pager for a Laravel paginator, same look as ImportHistoryTable's.
defineProps<{
    page: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
</script>

<template>
    <div v-if="page.last_page > 1" class="mt-4 flex items-center justify-between text-xs text-slate-500">
        <span>แสดง {{ page.from }}–{{ page.to }} จาก {{ page.total }} รายการ</span>
        <div class="flex items-center gap-2">
            <component
                :is="page.prev_page_url ? Link : 'span'"
                :href="page.prev_page_url ?? undefined"
                preserve-scroll
                class="rounded-lg border border-slate-200 px-3 py-1"
                :class="page.prev_page_url ? 'hover:bg-slate-50' : 'opacity-40'"
            >
                <i class="fa-solid fa-chevron-left"></i>
            </component>
            <span>หน้า {{ page.current_page }} / {{ page.last_page }}</span>
            <component
                :is="page.next_page_url ? Link : 'span'"
                :href="page.next_page_url ?? undefined"
                preserve-scroll
                class="rounded-lg border border-slate-200 px-3 py-1"
                :class="page.next_page_url ? 'hover:bg-slate-50' : 'opacity-40'"
            >
                <i class="fa-solid fa-chevron-right"></i>
            </component>
        </div>
    </div>
</template>
