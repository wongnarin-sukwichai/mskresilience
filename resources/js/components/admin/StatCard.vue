<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    unit?: string;
    icon: string;
    iconBg: string;
    iconColor: string;
}>();
</script>

<template>
    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" :class="[iconBg, iconColor]">
                <i :class="[icon, 'text-lg']"></i>
            </div>
            <div class="min-w-0">
                <p class="truncate text-sm text-slate-500">{{ label }}</p>
                <p class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-2xl font-bold text-slate-800">{{ value }}</span>
                    <span v-if="unit" class="text-sm text-slate-400">{{ unit }}</span>
                </p>
            </div>
        </div>
        <span class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-blue-600">
            ดูรายละเอียด
            <i class="fa-solid fa-chevron-right text-[9px]"></i>
        </span>
    </div>
</template>
