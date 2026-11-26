<script setup lang="ts">
// Admin-only ⓘ on a calculated card (owner 2026-11-26): opens a dialog with the formula, the values that went into
// it (city level: formula + totals only, owner's choice) and the import files the numbers came from, so an admin can
// check the result. Renders nothing for staff/member. Every value shown must be one the page or engine actually
// used — never a second, re-derived description.
import type { CalcSources, CalcStep, CalcValue } from '@/data/calcInfo';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    title: string;
    formula: string[];
    values: CalcValue[];
    notes?: string[];
    /** Sheet keys this card reads; omitted = every sheet in `sources`. */
    sheets?: string[];
    sources?: CalcSources | null;
    /**
     * The formula again with the real numbers put in (owner 2026-11-26: its own card, below สูตร). Only steps the
     * shown totals really produce: never city averages plugged into a per-community formula, which wouldn't match.
     */
    substituted?: CalcStep[];
    substitutedNote?: string;
}>();

const isAdmin = computed(() => usePage<SharedData>().props.auth.user?.role === 'admin');
const open = ref(false);

const sheetList = computed(() => {
    if (!props.sources) return [];
    const keys = props.sheets ?? Object.keys(props.sources);
    return keys.filter((k) => props.sources?.[k]).map((k) => ({ key: k, ...props.sources![k] }));
});

const sourceLabel: Record<string, string> = { web_add: 'เพิ่มผ่านเว็บ', web_edit: 'แก้ไขผ่านเว็บ', web_delete: 'ลบผ่านเว็บ' };
const fmtDate = (iso: string) => new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <template v-if="isAdmin">
        <button
            type="button"
            class="ml-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full align-middle text-[11px] text-slate-400 transition-colors hover:bg-blue-50 hover:text-blue-600 print:hidden"
            :title="`ที่มาของการคำนวณ: ${title}`"
            :aria-label="`ที่มาของการคำนวณ: ${title}`"
            @click.stop="open = true"
        >
            <i class="fa-solid fa-circle-info"></i>
        </button>

        <Teleport to="body">
            <Transition name="calc-fade">
                <div v-if="open" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4" @click.self="open = false">
                    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white text-left shadow-2xl">
                        <div class="flex items-center justify-between gap-3 bg-blue-600 px-6 py-4">
                            <h3 class="text-base font-semibold text-white">
                                <i class="fa-solid fa-calculator mr-1.5"></i>ที่มาของการคำนวณ · {{ title }}
                            </h3>
                            <button type="button" class="text-blue-100 hover:text-white" aria-label="ปิด" @click="open = false">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <div class="space-y-5 overflow-y-auto px-6 py-5 text-sm text-slate-700">
                            <section>
                                <h4 class="font-semibold text-slate-800">สูตร</h4>
                                <div class="mt-2 space-y-1 rounded-xl bg-slate-900 px-4 py-3 font-mono text-[12px] leading-relaxed text-slate-100">
                                    <p v-for="(f, i) in formula" :key="i">{{ f }}</p>
                                </div>
                            </section>

                            <!-- แทนค่า: its own card (owner 2026-11-26), so the numbers can be checked with a calculator. -->
                            <section v-if="substituted?.length" class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
                                <h4 class="font-semibold text-emerald-900"><i class="fa-solid fa-equals mr-1.5 text-emerald-600"></i>แทนค่า</h4>
                                <div class="mt-2 space-y-2">
                                    <div v-for="(s, i) in substituted" :key="i">
                                        <p v-if="s.label" class="text-xs font-medium text-emerald-800">{{ s.label }}</p>
                                        <p
                                            class="break-words font-mono text-[12px] leading-relaxed"
                                            :class="
                                                s.result ? 'rounded-lg bg-white px-2 py-1 font-semibold text-emerald-900 shadow-sm' : 'text-slate-700'
                                            "
                                        >
                                            {{ s.text }}
                                        </p>
                                    </div>
                                </div>
                                <p v-if="substitutedNote" class="mt-3 text-[11px] text-emerald-800/80">{{ substitutedNote }}</p>
                            </section>

                            <section>
                                <h4 class="font-semibold text-slate-800">ค่าที่ใช้</h4>
                                <dl class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-100">
                                    <div
                                        v-for="v in values"
                                        :key="v.label"
                                        class="flex items-baseline justify-between gap-4 px-3 py-1.5"
                                        :class="v.result ? 'bg-blue-50 font-semibold text-blue-800' : ''"
                                    >
                                        <dt :class="v.result ? '' : 'text-slate-500'">{{ v.label }}</dt>
                                        <dd class="text-right tabular-nums">{{ v.value }}</dd>
                                    </div>
                                </dl>
                                <ul v-if="notes?.length" class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-slate-500">
                                    <li v-for="n in notes" :key="n">{{ n }}</li>
                                </ul>
                            </section>

                            <section v-if="sources">
                                <h4 class="font-semibold text-slate-800">ไฟล์ที่นำมาคำนวณ</h4>
                                <p class="text-xs text-slate-500">
                                    ข้อมูลล่าสุดของแต่ละชุมชนในรอบนี้ มาจากการนำเข้าเหล่านี้ (เลขอ้างอิงตรงกับเมนูประวัตินำเข้า)
                                </p>
                                <div v-for="s in sheetList" :key="s.key" class="mt-3">
                                    <p class="text-xs font-semibold text-slate-600">{{ s.label }}</p>
                                    <ul class="mt-1 space-y-1">
                                        <li
                                            v-for="b in s.batches"
                                            :key="b.id"
                                            class="flex flex-wrap items-center gap-x-3 gap-y-0.5 rounded-lg bg-slate-50 px-3 py-1.5 text-xs"
                                        >
                                            <span class="font-mono text-slate-400">#{{ b.id }}</span>
                                            <span class="min-w-0 flex-1 truncate font-medium text-slate-700" :title="b.file ?? ''">
                                                <i
                                                    :class="
                                                        b.file
                                                            ? 'fa-regular fa-file-excel text-emerald-600'
                                                            : 'fa-solid fa-pen-to-square text-blue-500'
                                                    "
                                                    class="mr-1"
                                                ></i
                                                >{{ b.file ?? sourceLabel[b.source] ?? b.source }}
                                            </span>
                                            <span class="text-slate-500">{{ b.communities }} ชุมชน</span>
                                            <span class="text-slate-400">{{ fmtDate(b.at) }} · {{ b.user ?? '–' }}</span>
                                        </li>
                                        <li v-if="!s.batches.length" class="text-xs text-slate-400">ไม่มีข้อมูลจากกลุ่มงานนี้ในรอบนี้</li>
                                    </ul>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </template>
</template>

<style scoped>
.calc-fade-enter-active,
.calc-fade-leave-active {
    transition: opacity 0.2s ease;
}
.calc-fade-enter-from,
.calc-fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .calc-fade-enter-active,
    .calc-fade-leave-active {
        transition: none;
    }
}
</style>
