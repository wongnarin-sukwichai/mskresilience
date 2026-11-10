<script setup lang="ts">
// หน้า 2 ภาพรวมความเสี่ยงของเมือง (spec v5 §2). All numbers come live from App\Scoring\RiskEngine via
// RiskOverviewController. City values are population-weighted, and Δ compares each community with its own
// previous round (owner 2026-11-10).
import AnimatedNumber from '@/components/admin/AnimatedNumber.vue';
import BarChart from '@/components/admin/BarChart.vue';
import RiskGauge from '@/components/admin/RiskGauge.vue';
import { useInView } from '@/composables/useInView';
import {
    HAZARD_KEYS,
    RISK_LEVEL_KEYS,
    formatNumber,
    formatScore,
    formatThaiDate,
    groupLabel,
    hazardIcon,
    hazardLabel,
    levelOf,
    riskLevels,
} from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CityRisk, CommunityRiskSummary, HazardKey } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Animations (owner request 2026-11-10): numbers count up (AnimatedNumber), the gauge and bar chart tween
// (useTween), progress bars grow via a CSS width transition, and ranked lists slide (TransitionGroup). Each one
// starts when it scrolls into view, so nothing below the fold animates unseen.
const oneDecimal = (v: number) => v.toFixed(1);
const percent = (v: number) => `${v.toFixed(1)}%`;
const pctOf = (part: number | undefined, whole: number | undefined) => (whole ? ((part ?? 0) / whole) * 100 : null);
const capacityEl = ref<HTMLElement | null>(null);
const capacityInView = useInView(capacityEl, 0.4);

const props = defineProps<{
    fiscalYears: number[];
    year: number | null;
    city: CityRisk | null;
    communities: CommunityRiskSummary[];
}>();

// Filters: ช่วงข้อมูล reloads from the server (another as-of date); เลือกภัย is local, since every hazard is loaded.
const selectedYear = ref(props.year);
const hazard = ref<'ALL' | HazardKey>('ALL');
const latestYear = computed(() => (props.fiscalYears.length ? Math.max(...props.fiscalYears) : null));

function changeYear() {
    router.get(route('risk-overview.index'), { year: selectedYear.value }, { preserveScroll: true, preserveState: true });
}

const selected = computed(() => {
    if (!props.city) return null;
    return hazard.value === 'ALL' ? props.city.multi : props.city.hazards[hazard.value];
});
const selectedLabel = computed(() => (hazard.value === 'ALL' ? 'ภัยรวม (MULTI_HAZARD_HEVC)' : `${hazardLabel[hazard.value]} (${hazard.value}_HEVC)`));

// The hazard the exposure card describes: the selected one, or the city's dominant hazard under ภัยรวม.
const exposureHazard = computed<HazardKey | null>(() => (hazard.value === 'ALL' ? (props.city?.multi.dominant ?? null) : hazard.value));

function communityScore(c: CommunityRiskSummary): number | null {
    if (c.status !== 'ok') return null;
    return hazard.value === 'ALL' ? (c.multi?.hevc ?? null) : (c.hazards?.[hazard.value].hevc ?? null);
}

const ranked = computed(() =>
    props.communities
        .map((c) => ({ ...c, score: communityScore(c) }))
        .filter((c): c is typeof c & { score: number } => c.score !== null)
        .sort((a, b) => b.score - a.score),
);
const highRiskCount = computed(() => ranked.value.filter((c) => levelOf(c.score) === 'very_high').length);
const top5 = computed(() => ranked.value.slice(0, 5));

const hazardBars = computed(() =>
    HAZARD_KEYS.map((h) => {
        const value = props.city?.hazards[h].value ?? null;
        return {
            label: hazardLabel[h],
            value: value === null ? 0 : Number(value.toFixed(1)),
            color: value === null ? '#cbd5e1' : riskLevels[levelOf(value)].color,
        };
    }),
);

const vulnerable = computed(() => {
    const counts = props.city?.counts ?? {};
    return [
        { key: 'v_elderly_count', label: 'ผู้สูงอายุ', icon: 'fa-solid fa-person-cane', color: 'text-blue-600 bg-blue-50' },
        { key: 'v_assisted_evacuation_count', label: 'ต้องช่วยอพยพ', icon: 'fa-solid fa-wheelchair', color: 'text-violet-600 bg-violet-50' },
        { key: 'v_medical_dependent_count', label: 'พึ่งพาบริการแพทย์', icon: 'fa-solid fa-kit-medical', color: 'text-rose-600 bg-rose-50' },
    ].map((g) => ({ ...g, count: counts[g.key] ?? 0 }));
});

// ความพร้อมของเมือง: C4, C5, C8 are shared; C6/C7 exist per hazard, so they show only for one hazard. Under ภัยรวม,
// the per-hazard C_RAW are shown instead; no cross-hazard capacity total is made (spec §4.6).
const C5_HAZARDS: HazardKey[] = ['FLOOD', 'HEAT'];
const capacityBars = computed(() => {
    const g = props.city?.groups ?? {};
    if (hazard.value === 'ALL') {
        return [
            ...['C4', 'C5', 'C8'].map((k) => ({ key: k, label: `${k} ${groupLabel[k]}`, value: g[k] ?? null })),
            ...HAZARD_KEYS.map((h) => ({ key: `C_${h}`, label: `C รวม ${hazardLabel[h]}`, value: props.city?.hazards[h].cRaw ?? null })),
        ];
    }
    const h = hazard.value;
    return [
        { key: 'C4', label: `C4 ${groupLabel.C4}`, value: g.C4 ?? null },
        ...(C5_HAZARDS.includes(h) ? [{ key: 'C5', label: `C5 ${groupLabel.C5}`, value: g.C5 ?? null }] : []),
        { key: 'C6', label: `C6 ${groupLabel.C6}`, value: g[`C6_${h}`] ?? null },
        { key: 'C7', label: `C7 ${groupLabel.C7}`, value: g[`C7_${h}`] ?? null },
        { key: 'C8', label: `C8 ${groupLabel.C8}`, value: g.C8 ?? null },
        { key: 'C_RAW', label: `C รวม ${hazardLabel[h]}`, value: props.city?.hazards[h].cRaw ?? null },
    ];
});

// จุดที่ยังต้องปรับปรุง (spec §2.5): the city's 5 worst values, shown as data only (no generated advice).
// Gaps (V_WARNING, V_INFRA): higher is worse. Capacity (C4–C8): lower is worse.
const improvements = computed(() => {
    const g = props.city?.groups ?? {};
    const hazards = hazard.value === 'ALL' ? HAZARD_KEYS : [hazard.value];
    const items: { label: string; value: number; kind: 'gap' | 'capacity'; badness: number }[] = [];
    const gap = (label: string, value: number | null | undefined) => {
        if (value !== null && value !== undefined) items.push({ label, value, kind: 'gap', badness: value });
    };
    const cap = (label: string, value: number | null | undefined) => {
        if (value !== null && value !== undefined) items.push({ label, value, kind: 'capacity', badness: 5 - value });
    };
    gap(groupLabel.V_WARNING, g.V_WARNING);
    hazards.forEach((h) => gap(`${groupLabel.V_INFRA} (${hazardLabel[h]})`, g[`V_INFRA_${h}`]));
    cap(groupLabel.C4, g.C4);
    if (hazard.value === 'ALL' || C5_HAZARDS.includes(hazard.value)) cap(groupLabel.C5, g.C5);
    cap(groupLabel.C8, g.C8);
    if (hazard.value !== 'ALL') {
        cap(groupLabel.C6, g[`C6_${hazard.value}`]);
        cap(groupLabel.C7, g[`C7_${hazard.value}`]);
    }
    return items.sort((a, b) => b.badness - a.badness).slice(0, 5);
});

const setsDiffer = computed(() => {
    const now = (props.city?.criteriaSets ?? []).map((s) => s.id).join(',');
    const before = (props.city?.previousCriteriaSets ?? []).map((s) => s.id).join(',');
    return before !== '' && now !== before;
});

const showMissing = ref(false);

// HEVC: Δ > 0 means risk went up (warning); Δ < 0 means it went down (spec §2.6).
function deltaClass(delta: number) {
    if (Math.abs(delta) < 0.05) return 'text-slate-400';
    return delta > 0 ? 'text-red-600' : 'text-green-600';
}
function deltaIcon(delta: number) {
    if (Math.abs(delta) < 0.05) return 'fa-solid fa-minus';
    return delta > 0 ? 'fa-solid fa-arrow-up' : 'fa-solid fa-arrow-down';
}
function deltaText(delta: number) {
    return `${delta > 0 ? '+' : ''}${delta.toFixed(1)}`;
}

const card = 'rounded-2xl border border-slate-100 bg-white p-5 shadow-sm';
</script>

<template>
    <Head title="ภาพรวมความเสี่ยง" />

    <AdminLayout title="ภาพรวมความเสี่ยงของเมือง">
        <!-- Filters: sticky, so the hazard can be switched while looking at the lower blocks. The negative top/margins
             cancel <main>'s padding so the bar sits flush at the top edge of the scroll area. -->
        <div
            class="sticky -top-4 z-20 -mx-4 mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200/70 bg-slate-50/90 px-4 py-3 backdrop-blur lg:-top-6 lg:-mx-6 lg:px-6"
        >
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ช่วงข้อมูล</span>
                <select
                    v-model="selectedYear"
                    :disabled="!fiscalYears.length"
                    class="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    @change="changeYear"
                >
                    <option v-for="y in [...fiscalYears].reverse()" :key="y" :value="y">
                        ปีงบประมาณ {{ y }}{{ y === latestYear ? ' (ล่าสุด)' : '' }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">เลือกภัย</span>
                <select
                    v-model="hazard"
                    class="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                >
                    <option value="ALL">ภัยรวม</option>
                    <option v-for="h in HAZARD_KEYS" :key="h" :value="h">{{ hazardLabel[h] }}</option>
                </select>
            </label>
            <p v-if="city && year !== latestYear" class="pb-2 text-xs text-slate-400">ข้อมูล ณ 30 ก.ย. ของปีงบประมาณที่เลือก</p>
        </div>

        <div v-if="!city" class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            <i class="fa-solid fa-database mb-3 block text-3xl text-slate-300"></i>
            ยังไม่มีข้อมูลสำหรับคำนวณ — นำเข้าข้อมูลที่เมนู "นำเข้าข้อมูล" ก่อน
        </div>

        <template v-else>
            <!-- Top cards -->
            <div class="grid gap-5 lg:grid-cols-2">
                <div :class="card">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-500">
                            <i class="fa-solid fa-circle-exclamation text-lg"></i>
                        </span>
                        <div>
                            <h2 class="font-semibold text-slate-800">ระดับความเสี่ยงรวมของเมือง</h2>
                            <p class="text-xs text-slate-400">{{ selectedLabel }}</p>
                        </div>
                    </div>
                    <div class="mt-3 grid items-center gap-4 sm:grid-cols-2">
                        <div class="mx-auto w-full max-w-[240px]"><RiskGauge :value="selected?.value ?? null" /></div>
                        <div class="text-center sm:text-left">
                            <template v-if="selected?.value !== null && selected?.value !== undefined">
                                <p class="text-4xl font-bold text-slate-800">
                                    <AnimatedNumber :value="selected.value" :format="oneDecimal" />
                                    <span class="ml-1 text-lg font-medium text-slate-400">/ 100</span>
                                </p>
                                <span
                                    class="mt-2 inline-block rounded-full px-3 py-1 text-sm font-semibold transition-colors duration-500"
                                    :class="riskLevels[levelOf(selected.value)].badge"
                                    >{{ riskLevels[levelOf(selected.value)].label }}</span
                                >
                                <p v-if="selected.delta !== null" class="mt-2 text-sm" :class="deltaClass(selected.delta)">
                                    <i :class="deltaIcon(selected.delta)" class="mr-1"></i>{{ deltaText(selected.delta) }} จากรอบก่อน
                                </p>
                                <p v-else class="mt-2 text-xs text-slate-400">ยังไม่มีข้อมูลเปรียบเทียบ</p>
                            </template>
                            <p v-else class="text-sm text-slate-400">ไม่มีข้อมูลสำหรับภัยนี้</p>
                        </div>
                    </div>
                </div>

                <div :class="card">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-500">
                            <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                        </span>
                        <div>
                            <h2 class="font-semibold text-slate-800">ภัยที่ควรเฝ้าระวัง (ภัยเด่น)</h2>
                            <p class="text-xs text-slate-400">ภัยที่ HEVC ระดับเมืองสูงที่สุด</p>
                        </div>
                    </div>
                    <div v-if="city.multi.dominant" class="mt-5 flex items-center gap-5">
                        <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                            <i :class="hazardIcon[city.multi.dominant]" class="text-4xl"></i>
                        </span>
                        <div>
                            <p class="text-2xl font-bold text-slate-800">{{ hazardLabel[city.multi.dominant] }}</p>
                            <p class="mt-1 text-sm text-slate-500">
                                คะแนน
                                <span class="text-xl font-bold text-slate-800"
                                    ><AnimatedNumber :value="city.hazards[city.multi.dominant].value" :format="oneDecimal"
                                /></span>
                                <span
                                    v-if="city.hazards[city.multi.dominant].value !== null"
                                    class="ml-2 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="riskLevels[levelOf(city.hazards[city.multi.dominant].value!)].badge"
                                    >{{ riskLevels[levelOf(city.hazards[city.multi.dominant].value!)].label }}</span
                                >
                            </p>
                            <p
                                v-if="city.hazards[city.multi.dominant].delta !== null"
                                class="mt-1 text-sm"
                                :class="deltaClass(city.hazards[city.multi.dominant].delta!)"
                            >
                                <i :class="deltaIcon(city.hazards[city.multi.dominant].delta!)" class="mr-1"></i
                                >{{ deltaText(city.hazards[city.multi.dominant].delta!) }} จากรอบก่อน
                            </p>
                            <p v-else class="mt-1 text-xs text-slate-400">ยังไม่มีข้อมูลเปรียบเทียบ</p>
                        </div>
                    </div>
                    <p v-else class="mt-5 text-sm text-slate-400">ไม่มีข้อมูล</p>
                </div>
            </div>

            <!-- Count cards -->
            <div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <div :class="card">
                    <p class="text-sm font-semibold text-slate-700">จำนวนชุมชนเสี่ยงสูง</p>
                    <div class="mt-3 flex items-center gap-4">
                        <i class="fa-solid fa-house-flood-water text-3xl text-red-500"></i>
                        <div>
                            <p class="text-3xl font-bold text-slate-800"><AnimatedNumber :value="highRiskCount" /></p>
                            <p class="text-xs text-slate-400">จาก {{ city.scoredCommunities }} ชุมชนที่คำนวณได้ (คะแนน ≥ 75)</p>
                        </div>
                    </div>
                </div>
                <div :class="card">
                    <p class="text-sm font-semibold text-slate-700">
                        ประชาชนที่อาจได้รับผลกระทบ
                        <span v-if="exposureHazard" class="font-normal text-slate-400"
                            >({{ hazard === 'ALL' ? 'ภัยเด่น: ' : '' }}{{ hazardLabel[exposureHazard] }})</span
                        >
                    </p>
                    <div class="mt-3 flex items-center gap-4">
                        <i class="fa-solid fa-people-group text-3xl text-blue-500"></i>
                        <div v-if="exposureHazard">
                            <p class="text-3xl font-bold text-slate-800">
                                <AnimatedNumber :value="city.counts[`e_numerator_count_${exposureHazard.toLowerCase()}`]" />
                                <span class="ml-1 text-sm font-medium text-slate-400">คน</span>
                            </p>
                            <p class="text-xs text-slate-400">
                                <AnimatedNumber
                                    :value="pctOf(city.counts[`e_numerator_count_${exposureHazard.toLowerCase()}`], city.counts.population)"
                                    :format="percent"
                                />
                                ของประชากร {{ formatNumber(city.counts.population) }} คน
                            </p>
                        </div>
                    </div>
                </div>
                <div :class="[card, 'xl:col-span-2']">
                    <p class="text-sm font-semibold text-slate-700">กลุ่มเปราะบางสำคัญ</p>
                    <div class="mt-3 grid grid-cols-3 gap-3">
                        <div v-for="g in vulnerable" :key="g.key" class="text-center">
                            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full" :class="g.color">
                                <i :class="g.icon"></i>
                            </span>
                            <p class="mt-2 text-xl font-bold text-slate-800"><AnimatedNumber :value="g.count" /></p>
                            <p class="text-xs text-slate-500">{{ g.label }}</p>
                            <p class="text-[11px] text-slate-400">
                                <AnimatedNumber :value="pctOf(g.count, city.counts.population)" :format="percent" />
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hazard bars + status -->
            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <div :class="[card, 'xl:col-span-2']">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold text-slate-800">ระดับความเสี่ยงรายภัย (HEVC)</h2>
                        <div class="flex flex-wrap gap-3 text-[11px] text-slate-500">
                            <span v-for="k in RISK_LEVEL_KEYS" :key="k" class="flex items-center gap-1">
                                <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: riskLevels[k].color }"></span>
                                {{ riskLevels[k].range }} {{ riskLevels[k].label }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <BarChart :bars="hazardBars" :max="100" :decimals="1" :highlight="hazard === 'ALL' ? null : HAZARD_KEYS.indexOf(hazard)" />
                    </div>
                    <div class="grid grid-cols-5 pl-[4.5%] pr-[2%] text-center text-xs">
                        <div
                            v-for="h in HAZARD_KEYS"
                            :key="h"
                            class="transition-opacity duration-500"
                            :class="hazard === 'ALL' ? '' : hazard === h ? 'font-bold' : 'opacity-25'"
                        >
                            <span v-if="city.hazards[h].value === null" class="text-slate-400">ไม่มีข้อมูล</span>
                            <span v-else-if="city.hazards[h].delta === null" class="text-slate-300" title="ยังไม่มีข้อมูลเปรียบเทียบ">–</span>
                            <span v-else :class="deltaClass(city.hazards[h].delta!)">
                                <i :class="deltaIcon(city.hazards[h].delta!)" class="mr-0.5"></i>{{ deltaText(city.hazards[h].delta!) }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-400">ตัวเลขใต้กราฟคือผลต่างจากรอบก่อน (ลูกศรขึ้น = ความเสี่ยงเพิ่ม)</p>
                </div>

                <div :class="card">
                    <h2 class="font-semibold text-slate-800">สถานะข้อมูลล่าสุด</h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div class="flex items-center gap-3">
                            <i class="fa-regular fa-calendar w-5 text-center text-blue-500"></i>
                            <div>
                                <dt class="text-xs text-slate-400">วันที่ข้อมูลล่าสุด</dt>
                                <dd class="font-medium text-slate-700">{{ formatThaiDate(city.dataDate) }}</dd>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check w-5 text-center text-green-500"></i>
                            <div>
                                <dt class="text-xs text-slate-400">ชุมชนที่คำนวณได้</dt>
                                <dd class="font-medium text-slate-700">{{ city.scoredCommunities }} จาก {{ city.totalCommunities }} ชุมชน</dd>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-scale-balanced w-5 text-center text-slate-500"></i>
                            <div>
                                <dt class="text-xs text-slate-400">เกณฑ์คะแนนที่ใช้</dt>
                                <dd class="font-medium text-slate-700">
                                    <span v-for="(s, i) in city.criteriaSets" :key="s.id">{{ i ? ', ' : '' }}{{ s.name }}</span>
                                    <span v-if="!city.criteriaSets.length">–</span>
                                </dd>
                                <dd v-if="setsDiffer" class="mt-0.5 text-xs text-amber-600">
                                    <i class="fa-solid fa-triangle-exclamation mr-0.5"></i>รอบก่อนใช้เกณฑ์:
                                    <span v-for="(s, i) in city.previousCriteriaSets" :key="s.id">{{ i ? ', ' : '' }}{{ s.name }}</span>
                                </dd>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-clock-rotate-left w-5 text-center text-slate-400"></i>
                            <div>
                                <dt class="text-xs text-slate-400">รอบก่อน (สำหรับเปรียบเทียบ)</dt>
                                <dd class="font-medium text-slate-700">
                                    {{ city.previousDataDate ? formatThaiDate(city.previousDataDate) : 'ยังไม่มีข้อมูลรอบก่อน' }}
                                </dd>
                            </div>
                        </div>
                    </dl>
                    <div v-if="city.missing.length" class="mt-4 rounded-xl bg-slate-50 p-3 text-xs">
                        <button type="button" class="flex w-full items-center justify-between text-slate-600" @click="showMissing = !showMissing">
                            <span
                                ><i class="fa-solid fa-circle-half-stroke mr-1 text-slate-400"></i>ข้อมูลไม่ครบ {{ city.missing.length }} ชุมชน</span
                            >
                            <i :class="showMissing ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'"></i>
                        </button>
                        <ul v-if="showMissing" class="mt-2 space-y-1 text-slate-500">
                            <li v-for="m in city.missing" :key="m.code">
                                <span class="font-mono">{{ m.code }}</span> {{ m.name }} — ขาด {{ m.sheets.join(', ') }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Capacity + improvement points + top 5 -->
            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <div ref="capacityEl" :class="card">
                    <h2 class="font-semibold text-slate-800">ความพร้อมของเมือง</h2>
                    <p class="text-xs text-slate-400">
                        คะแนน 0–5 (สูง = พร้อมมาก){{ hazard === 'ALL' ? ' · C6/C7 แยกตามภัย ดูได้เมื่อเลือกภัย' : '' }}
                    </p>
                    <ul class="mt-4 space-y-3">
                        <li v-for="bar in capacityBars" :key="bar.key">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-600">{{ bar.label }}</span>
                                <span class="font-semibold text-slate-800"><AnimatedNumber :value="bar.value" :format="oneDecimal" /></span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    class="h-full rounded-full bg-emerald-500 transition-[width] duration-1000 ease-out motion-reduce:transition-none"
                                    :style="{ width: capacityInView ? `${((bar.value ?? 0) / 5) * 100}%` : '0%' }"
                                ></div>
                            </div>
                        </li>
                    </ul>
                </div>

                <div :class="card">
                    <h2 class="font-semibold text-slate-800">จุดที่ยังต้องปรับปรุง</h2>
                    <p class="text-xs text-slate-400">ค่าที่แย่ที่สุด 5 รายการของเมือง (จากข้อมูลจริง)</p>
                    <TransitionGroup tag="ul" name="rank" class="relative mt-4 space-y-2.5">
                        <li v-for="item in improvements" :key="item.label" class="flex items-start gap-2.5 text-sm">
                            <i
                                class="mt-0.5"
                                :class="
                                    item.kind === 'gap'
                                        ? 'fa-solid fa-triangle-exclamation text-red-500'
                                        : 'fa-solid fa-arrow-trend-down text-amber-500'
                                "
                            ></i>
                            <div class="min-w-0 flex-1">
                                <p class="text-slate-700">{{ item.label }}</p>
                                <p class="text-xs text-slate-400">
                                    {{ item.kind === 'gap' ? 'ช่องว่าง' : 'ความพร้อม' }} {{ formatScore(item.value) }} / 5
                                    <span class="text-slate-300">· {{ item.kind === 'gap' ? 'สูง = แย่' : 'ต่ำ = แย่' }}</span>
                                </p>
                            </div>
                        </li>
                        <li v-if="!improvements.length" key="empty" class="text-sm text-slate-400">ไม่มีข้อมูล</li>
                    </TransitionGroup>
                </div>

                <div :class="card">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold text-slate-800">5 ชุมชนเสี่ยงสูงสุด</h2>
                        <Link :href="route('community-map.index')" class="text-xs text-blue-600 hover:underline"
                            >ดูแผนที่ <i class="fa-solid fa-arrow-right"></i
                        ></Link>
                    </div>
                    <p class="text-xs text-slate-400">{{ hazard === 'ALL' ? 'เรียงตาม MULTI_HAZARD_HEVC' : `เรียงตาม ${hazardLabel[hazard]}` }}</p>
                    <TransitionGroup tag="ol" name="rank" class="relative mt-4 space-y-2">
                        <li v-for="(c, i) in top5" :key="c.code">
                            <Link
                                :href="route('community-map.index', { community: c.code, hazard: hazard === 'ALL' ? undefined : hazard })"
                                class="flex items-center gap-3 rounded-xl px-2 py-1.5 hover:bg-slate-50"
                            >
                                <span class="w-5 text-center text-sm font-bold text-slate-400">{{ i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-700">{{ c.name }}</p>
                                    <p v-if="hazard === 'ALL' && c.multi?.dominant" class="text-[11px] text-slate-400">
                                        <i :class="hazardIcon[c.multi.dominant]" class="mr-0.5"></i>ภัยเด่น {{ hazardLabel[c.multi.dominant] }}
                                    </p>
                                </div>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors duration-500"
                                    :class="riskLevels[levelOf(c.score)].badge"
                                >
                                    <AnimatedNumber :value="c.score" :format="oneDecimal" />
                                </span>
                            </Link>
                        </li>
                        <li v-if="!top5.length" key="empty" class="text-sm text-slate-400">ไม่มีข้อมูล</li>
                    </TransitionGroup>
                </div>
            </div>

            <p class="mt-5 text-[11px] leading-relaxed text-slate-400">
                คะแนนความเสี่ยงเป็นเครื่องมือเปรียบเทียบระดับความเสี่ยงระหว่างภัย พื้นที่ และช่วงเวลา ไม่ใช่การพยากรณ์ว่าจะเกิดภัยเมื่อใด ·
                ค่าระดับเมืองเป็นค่าเฉลี่ยถ่วงน้ำหนักตามจำนวนประชากรของชุมชนที่คำนวณได้
            </p>
        </template>
    </AdminLayout>
</template>

<style scoped>
/* Ranked lists (Top 5, จุดที่ยังต้องปรับปรุง): rows slide to their new place; new rows fade in, old ones fade out. */
.rank-move,
.rank-enter-active,
.rank-leave-active {
    transition: all 0.5s ease;
}
.rank-enter-from,
.rank-leave-to {
    opacity: 0;
    transform: translateX(16px);
}
.rank-leave-active {
    position: absolute;
    left: 0;
    right: 0;
}
@media (prefers-reduced-motion: reduce) {
    .rank-move,
    .rank-enter-active,
    .rank-leave-active {
        transition: none;
    }
}
</style>
