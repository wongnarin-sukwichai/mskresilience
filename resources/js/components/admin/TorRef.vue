<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// A small light-yellow "TOR …" badge (owner 2026-11-23) after a heading, naming the TOR clause(s) that part of the system answers, so the
// municipality's committee can trace each item during the acceptance presentation (owner 2026-11-23).
// Numbering: "4.1.1(3)-1" = scope of work (TOR pp. 4–6); "คุณลักษณะ 2.4-1" = the spec tables (pp. 7–9).
// Shown only while ตั้งค่าทั่วไป → แสดงเลขอ้างอิง TOR is on.
const props = defineProps<{ refs: string | string[] }>();

const page = usePage<SharedData>();
const text = computed(() => (Array.isArray(props.refs) ? props.refs.join(', ') : props.refs));
</script>

<template>
    <span
        v-if="page.props.showTorRefs"
        class="ml-2 inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-yellow-300 bg-yellow-100 px-1.5 py-0.5 align-middle text-[11px] font-medium text-yellow-800"
        :title="`ตอบข้อกำหนด TOR ข้อ ${text}`"
    >
        <i class="fa-solid fa-file-contract text-[10px] text-yellow-600"></i>TOR {{ text }}
    </span>
</template>
