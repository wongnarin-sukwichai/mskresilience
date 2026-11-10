<script setup lang="ts">
// "ดูวิธีคิด" (admin only, owner 2026-11-10). It shows one community's calculation step by step from the trace the
// engine itself produced (GET calc-trace/{code}), so the numbers are exactly the ones calculated, never a second
// description of the formula. Used on pages 3 and 4.
import { HAZARD_KEYS, formatThaiDate, hazardIcon, hazardLabel, levelOf, riskLevels } from '@/data/riskDisplay';
import type { HazardKey } from '@/types';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ code: string | null; year: number | null; initialHazard?: HazardKey | null }>();
const emit = defineEmits<{ close: [] }>();

interface Term {
    key: string;
    weight: number;
    value: number | null;
}
interface Step {
    value: number | null;
    raw?: number | null;
    note?: string;
    terms?: Term[];
}
interface TraceData {
    code: string;
    name: string;
    status: 'ok' | 'missing';
    missingSheets: string[];
    sources: Record<string, string>;
    dataDate: string | null;
    population: number | null;
    criteriaSet: { name: string; startFiscalYear: number } | null;
    constants: { wE: number; wV: number; c: number } | null;
    trace: Record<string, Step> | null;
}

const data = ref<TraceData | null>(null);
const loading = ref(false);
const failed = ref(false);
const hazard = ref<HazardKey>(props.initialHazard ?? 'FLOOD');

watch(
    () => props.code,
    async (code) => {
        data.value = null;
        failed.value = false;
        if (!code) return;
        hazard.value = props.initialHazard ?? 'FLOOD';
        loading.value = true;
        try {
            const url = new URL(route('calc-trace.show', code), window.location.origin);
            if (props.year) url.searchParams.set('year', String(props.year));
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(String(res.status));
            data.value = await res.json();
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const SHEET_LABEL: Record<string, string> = {
    disaster: 'งานป้องกันภัย',
    welfare: 'งานสวัสดิการสังคม',
    health: 'สาธารณสุข',
    education: 'กองการศึกษา',
    engineering: 'สำนักช่าง',
    public_relations: 'ประชาสัมพันธ์',
};

const LABEL: Record<string, string> = {
    S_ELDERLY: 'ผู้สูงอายุ',
    S_CHILD: 'เด็ก',
    S_DISABLED: 'ผู้พิการ',
    S_LOWINCOME: 'ผู้มีรายได้น้อย',
    S_BEDRIDDEN: 'ผู้ป่วยติดเตียง',
    S_MEDICAL: 'ผู้พึ่งพาบริการแพทย์',
    S_NCD: 'ผู้มี NCD',
    S_ASSIST_EVAC: 'ผู้ต้องช่วยอพยพ',
    S_COMM_ASSIST: 'ผู้ต้องช่วยด้านการสื่อสาร',
    V_SOCIAL: 'V_SOCIAL กลุ่มเปราะบางทางสังคม',
    V_HEALTH: 'V_HEALTH กลุ่มเปราะบางด้านสุขภาพ',
    V_ASSIST: 'V_ASSIST กลุ่มที่ต้องช่วยเหลือ',
    V_WARNING: 'V_WARNING การเข้าถึงคำเตือน',
    V_INFRA: 'V_INFRA โครงสร้างพื้นฐาน',
    C4_1: 'C4_1 อาสาสมัครต่อ 1,000 คน',
    C4_2: 'C4_2 การฝึกอบรม',
    C4_3: 'C4_3 การสื่อสารความเสี่ยง',
    C4_4: 'C4_4 DRR โรงเรียน',
    C5_1: 'C5_1 Green–Blue',
    C5_2: 'C5_2 NbS',
    C5_3: 'C5_3 การบำรุงรักษา',
    C6_1: 'C6_1 ยานพาหนะ',
    C6_2: 'C6_2 อุปกรณ์ช่วยชีวิต',
    C6_3: 'C6_3 ความพร้อมใช้งาน',
    C6_4: 'C6_4 อุปกรณ์เฉพาะภัย',
    C7_1: 'C7_1 แผนอพยพเฉพาะภัย',
    C7_2: 'C7_2 พื้นที่ปลอดภัย',
    C7_3: 'C7_3 การฝึกซ้อม',
    C8_1: 'C8_1 เครือข่ายทางการ',
    C8_2: 'C8_2 เวลาตอบสนอง',
    C8_3: 'C8_3 ความหลากหลายเครือข่าย',
    C8_4: 'C8_4 ประสบการณ์ร่วม',
    C4: 'C4 คนและการสื่อสาร',
    C5: 'C5 Green–Blue / NbS',
    C6: 'C6 อุปกรณ์และทรัพยากร',
    C7: 'C7 อพยพและพื้นที่ปลอดภัย',
    C8: 'C8 เครือข่ายและการตอบสนอง',
    R1: 'R1 แผนฟื้นฟู',
    R2: 'R2 ฟื้นฟูให้ดีกว่าเดิม',
    R3: 'R3 ฟื้นฟูเศรษฐกิจและสังคม',
    R4: 'R4 ฟื้นฟูกลุ่มเปราะบาง',
    R5: 'R5 การเรียนรู้และปรับระบบ',
    FLOOD_HEVC: 'น้ำท่วม',
    FIRE_HEVC: 'อัคคีภัย',
    STORM_HEVC: 'วาตภัย',
    HEAT_HEVC: 'ความร้อน',
    PM25_HEVC: 'ฝุ่น PM2.5',
};
const UNIT: Record<string, string> = { C4_1: 'คน/1,000', C4_2: 'ครั้ง', C6_3: '%', C8_1: 'หน่วยงาน', C8_2: 'ชม.' };
const label = (key: string) => LABEL[key] ?? key;

const trace = computed(() => data.value?.trace ?? null);
const step = (key: string): Step | null => trace.value?.[key] ?? null;

const fmt = (v: number | null | undefined, d = 2) => (v === null || v === undefined ? 'N/A' : Number.isInteger(v) ? String(v) : v.toFixed(d));

// A weighted step: Σ(w·x) over the terms with a value, ÷ Σw when some are N/A (re-normalization, spec §4.6).
function sums(s: Step | null) {
    const used = (s?.terms ?? []).filter((t) => t.value !== null);
    const weight = used.reduce((a, t) => a + t.weight, 0);
    const product = used.reduce((a, t) => a + t.weight * (t.value as number), 0);
    return { weight, product, renormalized: Math.abs(weight - 1) > 1e-6 && used.length > 0 };
}

const CONVERTED_V = ['S_ELDERLY', 'S_CHILD', 'S_DISABLED', 'S_LOWINCOME', 'S_BEDRIDDEN', 'S_MEDICAL', 'S_NCD', 'S_ASSIST_EVAC', 'S_COMM_ASSIST'];
const CONVERTED_C = ['C4_1', 'C4_2', 'C6_3', 'C8_1', 'C8_2'];

const hevcFormula = computed(() => {
    const c = data.value?.constants;
    const h = hazard.value;
    const e = step(`E_${h}`)?.value ?? null;
    const v = step(`V_${h}`)?.value ?? null;
    const ch = step(`C_HEVC_${h}`)?.value ?? null;
    const result = step(`${h}_HEVC`)?.value ?? null;
    if (!c || e === null || v === null || ch === null) return null;
    return { ...c, e, v, ch, result };
});
</script>

<template>
    <Transition name="fade">
        <div
            v-if="code"
            class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 bg-blue-600 px-6 py-4 text-white">
                    <div>
                        <h3 class="text-base font-semibold"><i class="fa-solid fa-calculator mr-1.5"></i>วิธีคิดคะแนน · {{ data?.name ?? code }}</h3>
                        <p class="text-xs text-blue-100">ทุกตัวเลขมาจากการคำนวณจริงของระบบ (เฉพาะผู้ดูแลระบบ)</p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-blue-100 hover:bg-white/15"
                        @click="emit('close')"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="custom-scrollbar flex-1 overflow-y-auto p-6 text-sm">
                    <p v-if="loading" class="py-10 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด</p>
                    <p v-else-if="failed" class="py-10 text-center text-red-600">โหลดข้อมูลไม่สำเร็จ</p>

                    <template v-else-if="data">
                        <!-- 1. Inputs used -->
                        <section>
                            <h4 class="font-semibold text-slate-800">1. ข้อมูลที่ใช้</h4>
                            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                <div v-for="(date, sheet) in data.sources" :key="sheet" class="rounded-lg bg-slate-50 px-3 py-2 text-xs">
                                    <span class="text-slate-500">{{ SHEET_LABEL[sheet] ?? sheet }}</span>
                                    <span class="float-right font-medium text-slate-700">{{ formatThaiDate(date) }}</span>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">
                                ประชากร {{ data.population?.toLocaleString('th-TH') ?? '–' }} คน · เกณฑ์คะแนน
                                <span class="font-medium text-slate-700">{{ data.criteriaSet?.name ?? '–' }}</span>
                                <template v-if="data.constants">
                                    · wE {{ data.constants.wE }} · wV {{ data.constants.wV }} · c {{ data.constants.c }}</template
                                >
                            </p>
                            <p v-if="data.status === 'missing'" class="mt-3 rounded-xl bg-amber-50 p-3 text-amber-800">
                                ข้อมูลไม่ครบ ยังคำนวณไม่ได้ — ขาด {{ data.missingSheets.join(', ') }}
                            </p>
                        </section>

                        <template v-if="trace">
                            <!-- 2. Raw → 0–5 -->
                            <section class="mt-6">
                                <h4 class="font-semibold text-slate-800">2. แปลงค่าดิบเป็นคะแนน 0–5 (ภาคผนวก B)</h4>
                                <div class="mt-2 grid gap-4 lg:grid-cols-2">
                                    <table class="w-full text-xs">
                                        <thead class="text-slate-400">
                                            <tr>
                                                <th class="pb-1 text-left font-medium">กลุ่มเปราะบาง</th>
                                                <th class="pb-1 text-right font-medium">จำนวน (คน)</th>
                                                <th class="pb-1 text-right font-medium">% ประชากร</th>
                                                <th class="pb-1 text-right font-medium">คะแนน</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-50">
                                            <tr v-for="k in CONVERTED_V" :key="k">
                                                <td class="py-1 text-slate-600">{{ label(k) }}</td>
                                                <td class="py-1 text-right font-mono">{{ fmt(step(k)?.raw ?? null, 0) }}</td>
                                                <td class="py-1 text-right font-mono text-slate-500">
                                                    {{ step(k)?.note?.replace('ร้อยละ ', '') ?? '–' }}
                                                </td>
                                                <td class="py-1 text-right font-mono font-semibold">{{ fmt(step(k)?.value ?? null) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <table class="w-full text-xs">
                                        <thead class="text-slate-400">
                                            <tr>
                                                <th class="pb-1 text-left font-medium">ความพร้อม</th>
                                                <th class="pb-1 text-right font-medium">ค่าดิบ</th>
                                                <th class="pb-1 text-right font-medium">คะแนน</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-50">
                                            <tr v-for="k in CONVERTED_C" :key="k">
                                                <td class="py-1 text-slate-600">{{ label(k) }}</td>
                                                <td class="py-1 text-right font-mono">
                                                    {{ fmt(step(k)?.raw ?? null) }} <span class="text-slate-400">{{ UNIT[k] }}</span>
                                                </td>
                                                <td class="py-1 text-right font-mono font-semibold">{{ fmt(step(k)?.value ?? null) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-1 text-[11px] text-slate-400">คะแนนอื่นที่หน่วยงานกรอกเป็น 0–5 อยู่แล้วใช้ตามที่กรอก</p>
                            </section>

                            <!-- 3. Shared group scores -->
                            <section class="mt-6">
                                <h4 class="font-semibold text-slate-800">3. คะแนนรายกลุ่ม (ใช้ร่วมกันทุกภัย)</h4>
                                <div class="mt-2 grid gap-4 lg:grid-cols-2">
                                    <div
                                        v-for="k in ['V_SOCIAL', 'V_HEALTH', 'V_ASSIST', 'C4', 'C5', 'C8']"
                                        :key="k"
                                        class="rounded-xl border border-slate-100 p-3"
                                    >
                                        <p class="text-xs font-semibold text-slate-700">{{ label(k) }}</p>
                                        <table class="mt-1 w-full text-xs">
                                            <tbody>
                                                <tr v-for="t in step(k)?.terms ?? []" :key="t.key">
                                                    <td class="py-0.5 text-slate-500">{{ label(t.key) }}</td>
                                                    <td class="py-0.5 text-right font-mono">{{ fmt(t.value) }} × {{ t.weight }}</td>
                                                    <td class="py-0.5 text-right font-mono text-slate-500">
                                                        {{ t.value === null ? 'ตัดออก' : (t.value * t.weight).toFixed(3) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p class="mt-1 border-t border-slate-100 pt-1 text-right font-mono text-xs">
                                            <span v-if="sums(step(k)).renormalized" class="text-amber-600"
                                                >÷ {{ sums(step(k)).weight.toFixed(2) }} ·
                                            </span>
                                            = <span class="font-bold text-slate-800">{{ fmt(step(k)?.value ?? null, 3) }}</span>
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">
                                    V_WARNING (ใช้ค่าที่กรอก) =
                                    <span class="font-mono font-semibold">{{ fmt(step('V_WARNING')?.value ?? null) }}</span>
                                </p>
                            </section>

                            <!-- 4. Per hazard -->
                            <section class="mt-6">
                                <h4 class="font-semibold text-slate-800">4. คะแนนรายภัย</h4>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <button
                                        v-for="h in HAZARD_KEYS"
                                        :key="h"
                                        type="button"
                                        class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
                                        :class="hazard === h ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                        @click="hazard = h"
                                    >
                                        <i :class="hazardIcon[h]" class="mr-1"></i>{{ hazardLabel[h] }}
                                    </button>
                                </div>
                                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                                    <div class="rounded-xl border border-slate-100 p-3 text-xs">
                                        <p class="font-semibold text-slate-700">E การเปิดรับภัย</p>
                                        <p class="mt-1 text-slate-600">
                                            {{ fmt(step(`E_${hazard}`)?.raw ?? null, 0) }} คน → {{ step(`E_${hazard}`)?.note ?? '–' }} → คะแนน
                                            <span class="font-mono font-bold">{{ fmt(step(`E_${hazard}`)?.value ?? null) }}</span>
                                        </p>
                                        <p class="mt-2 font-semibold text-slate-700">
                                            V_INFRA (ใช้ค่าที่กรอก) = {{ fmt(step(`V_INFRA_${hazard}`)?.value ?? null) }}
                                        </p>
                                    </div>
                                    <div
                                        v-for="k in [`V_${hazard}`, `C6_${hazard}`, `C7_${hazard}`, `C_RAW_${hazard}`]"
                                        :key="k"
                                        class="rounded-xl border border-slate-100 p-3"
                                    >
                                        <p class="text-xs font-semibold text-slate-700">
                                            {{ k.replace(`_${hazard}`, '') }} {{ hazardLabel[hazard] }}
                                        </p>
                                        <table class="mt-1 w-full text-xs">
                                            <tbody>
                                                <tr v-for="t in step(k)?.terms ?? []" :key="t.key">
                                                    <td class="py-0.5 text-slate-500">{{ label(t.key) }}</td>
                                                    <td class="py-0.5 text-right font-mono">{{ fmt(t.value) }} × {{ t.weight }}</td>
                                                    <td class="py-0.5 text-right font-mono text-slate-500">
                                                        {{ t.value === null ? 'ตัดออก' : (t.value * t.weight).toFixed(3) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p class="mt-1 border-t border-slate-100 pt-1 text-right font-mono text-xs">
                                            <span v-if="sums(step(k)).renormalized" class="text-amber-600"
                                                >÷ {{ sums(step(k)).weight.toFixed(2) }} ·
                                            </span>
                                            = <span class="font-bold text-slate-800">{{ fmt(step(k)?.value ?? null, 3) }}</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-xl bg-blue-50 p-4 font-mono text-xs text-blue-900">
                                    <template v-if="hevcFormula">
                                        <p>
                                            C_HEVC = 1 + 0.8 × {{ fmt(step(`C_RAW_${hazard}`)?.value ?? null, 3) }} = {{ hevcFormula.ch.toFixed(3) }}
                                        </p>
                                        <p class="mt-1">
                                            HEVC = 20 × ({{ hevcFormula.wE }} × {{ hevcFormula.e }} + {{ hevcFormula.wV }} ×
                                            {{ hevcFormula.v.toFixed(3) }}) × (1 − {{ hevcFormula.c }} × {{ hevcFormula.ch.toFixed(3) }})
                                        </p>
                                        <p class="mt-1 text-sm">
                                            = <span class="font-bold">{{ hevcFormula.result?.toFixed(2) }}</span>
                                            <span
                                                v-if="hevcFormula.result !== null"
                                                class="ml-2 rounded-full px-2 py-0.5 font-sans text-xs font-semibold"
                                                :class="riskLevels[levelOf(hevcFormula.result)].badge"
                                                >{{ riskLevels[levelOf(hevcFormula.result)].label }}</span
                                            >
                                        </p>
                                    </template>
                                    <p v-else class="font-sans">ภัยนี้เป็น N/A (ไม่มีค่า E, V หรือ C) จึงไม่นำไปรวมใน MULTI</p>
                                </div>
                            </section>

                            <!-- 5. Multi + recovery -->
                            <section class="mt-6 grid gap-4 lg:grid-cols-2">
                                <div v-for="k in ['MULTI_HEVC', 'RECOVERY_AVG']" :key="k" class="rounded-xl border border-slate-100 p-3">
                                    <p class="text-xs font-semibold text-slate-700">
                                        {{ k === 'MULTI_HEVC' ? '5. ความเสี่ยงรวม MULTI_HAZARD_HEVC' : '6. ความพร้อมฟื้นฟู RECOVERY_AVG' }}
                                    </p>
                                    <table class="mt-1 w-full text-xs">
                                        <tbody>
                                            <tr v-for="t in step(k)?.terms ?? []" :key="t.key">
                                                <td class="py-0.5 text-slate-500">{{ label(t.key) }}</td>
                                                <td class="py-0.5 text-right font-mono">{{ fmt(t.value) }} × {{ t.weight }}</td>
                                                <td class="py-0.5 text-right font-mono text-slate-500">
                                                    {{ t.value === null ? 'ตัดออก' : (t.value * t.weight).toFixed(3) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p class="mt-1 border-t border-slate-100 pt-1 text-right font-mono text-xs">
                                        <span v-if="sums(step(k)).renormalized" class="text-amber-600"
                                            >÷ {{ sums(step(k)).weight.toFixed(2) }} ·
                                        </span>
                                        = <span class="font-bold text-slate-800">{{ fmt(step(k)?.value ?? null, 2) }}</span>
                                    </p>
                                </div>
                            </section>
                        </template>
                    </template>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
