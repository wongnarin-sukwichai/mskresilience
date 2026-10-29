<script setup lang="ts">
// Empty-state body for a display page whose content hasn't been built yet (the 6 display pages were reset
// to empty on 2026-10-29 and get rebuilt one at a time). Replace the whole <PagePlaceholder> when the
// page's real content lands.
defineProps<{
    title: string;
    description?: string;
    icon: string;
}>();
</script>

<template>
    <div class="flex min-h-[60vh] flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center">
        <span class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
            <i :class="[icon, 'text-2xl']"></i>
        </span>
        <h2 class="text-lg font-semibold text-slate-800">{{ title }}</h2>
        <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
        <p class="mt-4 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-500">
            <i class="fa-solid fa-person-digging mr-1"></i>อยู่ระหว่างการพัฒนา
        </p>
    </div>
</template>
