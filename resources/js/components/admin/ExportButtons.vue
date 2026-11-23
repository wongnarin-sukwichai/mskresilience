<script setup lang="ts">
import TorRef from '@/components/admin/TorRef.vue';
import { printPage } from '@/composables/usePrint';

// "ส่งออก Excel" + "บันทึก PDF" (TOR 4.4-4 "Export PDF/Excel ได้"). Excel is built on the server (ExportController,
// a real .xlsx); PDF is the browser's print → "Save as PDF" (owner 2026-11-23 option ก), with the print layout in
// app.css and AdminLayout. Hidden on paper.
withDefaults(defineProps<{ excelHref?: string | null; pdf?: boolean }>(), { excelHref: null, pdf: true });
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 print:hidden">
        <a
            v-if="excelHref"
            :href="excelHref"
            class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-white px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50"
        >
            <i class="fa-solid fa-file-excel"></i>ส่งออก Excel
        </a>
        <button
            v-if="pdf"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50"
            title="เปิดหน้าต่างพิมพ์ แล้วเลือก “บันทึกเป็น PDF”"
            @click="printPage"
        >
            <i class="fa-solid fa-file-pdf"></i>บันทึก PDF
        </button>
        <TorRef :refs="['4.4-4']" />
    </div>
</template>
