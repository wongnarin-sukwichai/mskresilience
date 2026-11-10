<script setup lang="ts">
// หน้า 4 ปัจจัยความเสี่ยงและความพร้อม (spec v5 §4). Scores come live from App\Scoring\RiskEngine via
// RiskFactorsController. Owner decisions 2026-11-10:
// - Scope is the city (population-weighted) or one community.
// - 5 hazard tabs; the default is the city's dominant hazard.
// - Items with no input column are left out.
// - "ที่มาของคะแนน" shows V_h and C_h with their weights.
import AnimatedNumber from '@/components/admin/AnimatedNumber.vue';
import CalcTraceDialog from '@/components/admin/CalcTraceDialog.vue';
import ScoreBar from '@/components/admin/ScoreBar.vue';
import ScoreRing from '@/components/admin/ScoreRing.vue';
import { HAZARD_KEYS, formatScore, formatThaiDate, groupLabel, hazardIcon, hazardLabel, scoreColor } from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CityFactors, CommunityFactors, HazardKey, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    fiscalYears: number[];
    year: number | null;
    initialCommunity: string | null;
    initialHazard: string | null;
    city: CityFactors | null;
    communities: CommunityFactors[];
    weights: Record<string, { v_hazard: Record<string, Record<string, number>>; c_hazard: Record<string, Record<string, number>> }>;
}>();

// "ดูวิธีคิด" is admin-only (the endpoint is role:admin too).
const isAdmin = computed(() => usePage<SharedData>().props.auth.user?.role === 'admin');
const traceCode = ref<string | null>(null);

// ---------------------------------------------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------------------------------------------
const selectedYear = ref(props.year);
const latestYear = computed(() => (props.fiscalYears.length ? Math.max(...props.fiscalYears) : null));
const byCode = computed(() => new Map(props.communities.map((c) => [c.code, c])));
const scope = ref<string>(
    props.initialCommunity && props.communities.some((c) => c.code === props.initialCommunity) ? props.initialCommunity : 'CITY',
);
const hazard = ref<HazardKey>(
    HAZARD_KEYS.includes(props.initialHazard as HazardKey) ? (props.initialHazard as HazardKey) : (props.city?.dominant ?? 'FLOOD'),
);

function changeYear() {
    router.get(
        route('risk-factors.index'),
        { year: selectedYear.value, community: scope.value === 'CITY' ? undefined : scope.value, hazard: hazard.value },
        { preserveScroll: true },
    );
}

// Keep the URL shareable without an Inertia visit (same as page 3).
watch([scope, hazard], () => {
    const url = new URL(window.location.href);
    if (scope.value === 'CITY') url.searchParams.delete('community');
    else url.searchParams.set('community', scope.value);
    url.searchParams.set('hazard', hazard.value);
    window.history.replaceState(window.history.state, '', url);
});

const community = computed(() => (scope.value === 'CITY' ? null : (byCode.value.get(scope.value) ?? null)));

// ---------------------------------------------------------------------------------------------------------------
// One view model for both scopes
// ---------------------------------------------------------------------------------------------------------------
interface View {
    population: number;
    counts: Record<string, number | null>;
    eCount: number | null;
    ePercent: number | null;
    eScore: number | null;
    v: number | null;
    vDelta: number | null;
    cRaw: number | null;
    cDelta: number | null;
    groups: Record<string, number | null>;
    groupDelta: (key: string) => number | null;
    setId: number | null;
    dataDate: string | null;
}

const view = computed<View | null>(() => {
    const h = hazard.value;
    const c = community.value;
    if (c) {
        if (c.status !== 'ok' || !c.hazards || !c.groups) return null;
        const prev = c.previous;
        const diff = (now: number | null, before: number | null | undefined) =>
            now === null || before === null || before === undefined ? null : now - before;
        return {
            population: c.population ?? 0,
            counts: c.counts,
            eCount: c.counts[`e_numerator_count_${h.toLowerCase()}`] ?? null,
            ePercent: c.hazards[h].ePercent,
            eScore: c.hazards[h].e,
            v: c.hazards[h].v,
            vDelta: diff(c.hazards[h].v, prev?.hazards[h].v),
            cRaw: c.hazards[h].cRaw,
            cDelta: diff(c.hazards[h].cRaw, prev?.hazards[h].cRaw),
            groups: c.groups,
            groupDelta: (k) => diff(c.groups?.[k] ?? null, prev?.groups[k]),
            setId: c.criteriaSet?.id ?? null,
            dataDate: c.dataDate,
        };
    }
    const city = props.city;
    if (!city) return null;
    return {
        population: city.counts.population ?? 0,
        counts: city.counts,
        eCount: city.exposure[h].count,
        ePercent: city.exposure[h].percent,
        eScore: city.exposure[h].score,
        v: city.hazards[h].v,
        vDelta: city.hazards[h].vDelta,
        cRaw: city.hazards[h].cRaw,
        cDelta: city.hazards[h].cRawDelta,
        groups: city.groups,
        groupDelta: (k) => city.groupDeltas[k] ?? null,
        setId: city.criteriaSets.at(-1)?.id ?? null,
        dataDate: city.dataDate,
    };
});

// Exposure ranking (always city-wide); the selected community is highlighted, or listed below if outside the Top 5.
const exposureRanked = computed(() =>
    props.communities
        .filter((c) => c.status === 'ok' && c.hazards?.[hazard.value].ePercent !== null)
        .map((c) => ({ code: c.code, name: c.name, pct: c.hazards![hazard.value].ePercent as number }))
        .sort((a, b) => b.pct - a.pct),
);
const exposureTop5 = computed(() => exposureRanked.value.slice(0, 5));
const selectedRank = computed(() => {
    if (!community.value) return null;
    const i = exposureRanked.value.findIndex((r) => r.code === community.value!.code);
    return i >= 5 ? { rank: i + 1, row: exposureRanked.value[i] } : null;
});

const VULNERABLE = [
    { key: 'v_elderly_count', label: 'ผู้สูงอายุ', icon: 'fa-solid fa-person-cane', color: 'text-blue-600 bg-blue-50' },
    { key: 'v_child_count', label: 'เด็ก', icon: 'fa-solid fa-child', color: 'text-amber-600 bg-amber-50' },
    { key: 'v_disabled_count', label: 'ผู้พิการ', icon: 'fa-solid fa-wheelchair', color: 'text-violet-600 bg-violet-50' },
    { key: 'v_bedridden_count', label: 'ติดเตียง', icon: 'fa-solid fa-bed', color: 'text-rose-600 bg-rose-50' },
    { key: 'v_medical_dependent_count', label: 'พึ่งพาบริการแพทย์', icon: 'fa-solid fa-kit-medical', color: 'text-red-600 bg-red-50' },
    {
        key: 'v_assisted_evacuation_count',
        label: 'ต้องช่วยอพยพ',
        icon: 'fa-solid fa-person-walking-arrow-right',
        color: 'text-indigo-600 bg-indigo-50',
    },
    { key: 'v_communication_assistance_count', label: 'ต้องช่วยด้านการสื่อสาร', icon: 'fa-solid fa-comment-dots', color: 'text-teal-600 bg-teal-50' },
    { key: 'v_low_income_count', label: 'มีรายได้น้อย', icon: 'fa-solid fa-hand-holding-dollar', color: 'text-emerald-600 bg-emerald-50' },
    { key: 'v_ncd_count', label: 'ผู้มี NCD', icon: 'fa-solid fa-heart-pulse', color: 'text-pink-600 bg-pink-50' },
];

const gaps = computed(() => [
    { key: 'V_WARNING', label: groupLabel.V_WARNING },
    { key: `V_INFRA_${hazard.value}`, label: `${groupLabel.V_INFRA} (${hazardLabel[hazard.value]})` },
]);

const C5_HAZARDS: HazardKey[] = ['FLOOD', 'HEAT'];
const capacity = computed(() => {
    const h = hazard.value;
    return [
        { key: 'C4', code: 'C4' },
        ...(C5_HAZARDS.includes(h) ? [{ key: 'C5', code: 'C5' }] : []),
        { key: `C6_${h}`, code: 'C6' },
        { key: `C7_${h}`, code: 'C7' },
        { key: 'C8', code: 'C8' },
    ].map((r) => ({ ...r, label: `${r.code} ${groupLabel[r.code]}` }));
});

// ---------------------------------------------------------------------------------------------------------------
// ที่มาของคะแนน: V_h and C_h as weighted sums of their components (weights from the criteria set used)
// ---------------------------------------------------------------------------------------------------------------
interface OriginRow {
    label: string;
    score: number | null;
    weight: number;
    product: number | null;
}

function origin(section: 'v_hazard' | 'c_hazard', components: { key: string; groupKey: string; label: string }[]) {
    const v = view.value;
    const w = v?.setId ? props.weights[v.setId]?.[section]?.[hazard.value] : null;
    if (!v || !w) return null;
    const rows: OriginRow[] = components
        .filter((c) => w[c.key] !== undefined)
        .map((c) => {
            const score = v.groups[c.groupKey] ?? null;
            return { label: c.label, score, weight: w[c.key], product: score === null ? null : score * w[c.key] };
        });
    const usedWeight = rows.filter((r) => r.product !== null).reduce((s, r) => s + r.weight, 0);
    const sum = rows.reduce((s, r) => s + (r.product ?? 0), 0);
    return { rows, usedWeight, total: usedWeight > 0 ? sum / usedWeight : null, renormalized: Math.abs(usedWeight - 1) > 1e-6 };
}

const vOrigin = computed(() =>
    origin('v_hazard', [
        { key: 'V_SOCIAL', groupKey: 'V_SOCIAL', label: 'กลุ่มเปราะบางทางสังคม' },
        { key: 'V_HEALTH', groupKey: 'V_HEALTH', label: 'กลุ่มเปราะบางด้านสุขภาพ' },
        { key: 'V_ASSIST', groupKey: 'V_ASSIST', label: 'กลุ่มที่ต้องช่วยเหลือ' },
        { key: 'V_WARNING', groupKey: 'V_WARNING', label: groupLabel.V_WARNING },
        { key: 'V_INFRA', groupKey: `V_INFRA_${hazard.value}`, label: groupLabel.V_INFRA },
    ]),
);
const cOrigin = computed(() =>
    origin('c_hazard', [
        { key: 'C4', groupKey: 'C4', label: `C4 ${groupLabel.C4}` },
        { key: 'C5', groupKey: 'C5', label: `C5 ${groupLabel.C5}` },
        { key: 'C6', groupKey: `C6_${hazard.value}`, label: `C6 ${groupLabel.C6}` },
        { key: 'C7', groupKey: `C7_${hazard.value}`, label: `C7 ${groupLabel.C7}` },
        { key: 'C8', groupKey: 'C8', label: `C8 ${groupLabel.C8}` },
    ]),
);

// Δ colors: for V and gaps a rise is bad; for capacity a rise is good.
function deltaClass(delta: number, goodWhenUp: boolean) {
    if (Math.abs(delta) < 0.05) return 'text-slate-400';
    return delta > 0 === goodWhenUp ? 'text-green-600' : 'text-red-600';
}
function deltaIcon(delta: number) {
    if (Math.abs(delta) < 0.05) return 'fa-solid fa-minus';
    return delta > 0 ? 'fa-solid fa-arrow-up' : 'fa-solid fa-arrow-down';
}
const signed = (d: number) => `${d > 0 ? '+' : ''}${d.toFixed(1)}`;

const oneDecimal = (v: number) => v.toFixed(1);
const twoDecimals = (v: number) => v.toFixed(2);
const percent = (v: number) => `${v.toFixed(1)}%`;
const pctOf = (part: number | null | undefined, whole: number) => (whole > 0 && part !== null && part !== undefined ? (part / whole) * 100 : null);
const card = 'rounded-2xl border border-slate-100 bg-white p-5 shadow-sm';
const selectClass =
    'rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="ปัจจัยความเสี่ยง" />

    <AdminLayout title="ปัจจัยความเสี่ยงและความพร้อม (รายภัย)">
        <!-- Filters (sticky) -->
        <div
            class="sticky -top-4 z-20 -mx-4 mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200/70 bg-slate-50/90 px-4 py-3 backdrop-blur lg:-top-6 lg:-mx-6 lg:px-6"
        >
            <div class="flex flex-wrap gap-1 rounded-2xl border border-slate-200 bg-white p-1">
                <button
                    v-for="h in HAZARD_KEYS"
                    :key="h"
                    type="button"
                    class="flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-medium transition-colors duration-300"
                    :class="hazard === h ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'"
                    @click="hazard = h"
                >
                    <i :class="hazardIcon[h]"></i>{{ hazardLabel[h] }}
                </button>
            </div>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ขอบเขต</span>
                <select v-model="scope" :class="selectClass">
                    <option value="CITY">ทั้งเมือง</option>
                    <option v-for="c in [...communities].sort((a, b) => a.name.localeCompare(b.name, 'th'))" :key="c.code" :value="c.code">
                        {{ c.name }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ช่วงข้อมูล</span>
                <select v-model="selectedYear" :disabled="!fiscalYears.length" :class="selectClass" @change="changeYear">
                    <option v-for="y in [...fiscalYears].reverse()" :key="y" :value="y">
                        ปีงบประมาณ {{ y }}{{ y === latestYear ? ' (ล่าสุด)' : '' }}
                    </option>
                </select>
            </label>
        </div>

        <div v-if="!city" class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            <i class="fa-solid fa-database mb-3 block text-3xl text-slate-300"></i>
            ยังไม่มีข้อมูลสำหรับคำนวณ — นำเข้าข้อมูลที่เมนู "นำเข้าข้อมูล" ก่อน
        </div>

        <div
            v-else-if="community && !view"
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 shadow-sm"
        >
            <p class="font-semibold">
                <i class="fa-solid fa-circle-half-stroke mr-1 text-slate-400"></i>{{ community.name }}: ข้อมูลไม่ครบ ยังคำนวณไม่ได้
            </p>
            <p class="mt-1 text-xs">ขาดข้อมูลจาก: {{ community.missingSheets.join(', ') }}</p>
        </div>

        <template v-else-if="view">
            <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2">
                <p class="text-xs text-slate-500">
                    <i class="fa-solid fa-location-dot mr-1 text-blue-500"></i>
                    <template v-if="community">
                        ชุมชน<span class="font-semibold text-slate-700">{{ community.name }}</span> · ประชากร
                        <AnimatedNumber :value="view.population" /> คน
                    </template>
                    <template v-else>
                        ทั้งเมือง ({{ city.scoredCommunities }} จาก {{ city.totalCommunities }} ชุมชนที่คำนวณได้ · ค่าเฉลี่ยถ่วงน้ำหนักตามประชากร)
                    </template>
                    · ข้อมูล {{ formatThaiDate(view.dataDate) }}
                </p>
                <div v-if="community" class="ml-auto flex items-center gap-2">
                    <Link
                        :href="route('community-map.index', { community: community.code, hazard })"
                        class="flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100"
                    >
                        <i class="fa-solid fa-map-location-dot"></i>ไปยังแผนที่
                    </Link>
                    <button
                        v-if="isAdmin"
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                        @click="traceCode = community.code"
                    >
                        <i class="fa-solid fa-calculator"></i>การคำนวณ
                    </button>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <!-- E -->
                <div class="space-y-5">
                    <div :class="card">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-600"
                                ><i class="fa-solid fa-people-group"></i
                            ></span>
                            <div>
                                <h2 class="font-semibold text-slate-800">ผู้ที่อาจได้รับผลกระทบ (Exposure)</h2>
                                <p class="text-xs text-slate-400">{{ hazardLabel[hazard] }}</p>
                            </div>
                        </div>
                        <p class="mt-4 text-4xl font-bold text-slate-800">
                            <AnimatedNumber :value="view.eCount" /> <span class="text-base font-medium text-slate-400">คน</span>
                        </p>
                        <p class="mt-1 text-sm text-slate-500">
                            <AnimatedNumber :value="view.ePercent" :format="percent" /> ของประชากร ·
                            <span class="font-semibold text-slate-700">คะแนน E {{ formatScore(view.eScore, 0) }}/5</span>
                        </p>
                        <p class="mt-2 text-[11px] text-slate-400">E 0–5: 0% = 0, &gt;0–20% = 1, &gt;20–40% = 2, … , &gt;80% = 5</p>
                    </div>

                    <div :class="card">
                        <h3 class="font-semibold text-slate-800">สัดส่วนประชากรเปิดรับภัย รายชุมชน (Top 5)</h3>
                        <TransitionGroup tag="ul" name="rank" class="relative mt-4 space-y-3">
                            <li v-for="row in exposureTop5" :key="row.code">
                                <div class="flex items-center justify-between text-sm">
                                    <span :class="community?.code === row.code ? 'font-bold text-blue-700' : 'text-slate-600'">{{ row.name }}</span>
                                    <span class="font-semibold text-slate-800"><AnimatedNumber :value="row.pct" :format="percent" /></span>
                                </div>
                                <ScoreBar
                                    :value="row.pct"
                                    :max="Math.max(exposureTop5[0]?.pct ?? 1, 1)"
                                    :color="community?.code === row.code ? '#1d4ed8' : '#3b82f6'"
                                    class="mt-1"
                                />
                            </li>
                        </TransitionGroup>
                        <p v-if="selectedRank" class="mt-3 border-t border-slate-100 pt-3 text-sm text-blue-700">
                            อันดับ {{ selectedRank.rank }}: <span class="font-semibold">{{ selectedRank.row.name }}</span>
                            {{ selectedRank.row.pct.toFixed(1) }}%
                        </p>
                    </div>
                </div>

                <!-- V -->
                <div class="space-y-5">
                    <div :class="card">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-violet-50 text-violet-600"
                                    ><i class="fa-solid fa-user-shield"></i
                                ></span>
                                <h2 class="font-semibold text-slate-800">ความเปราะบาง (Vulnerability)</h2>
                            </div>
                            <div class="text-right">
                                <p class="whitespace-nowrap text-2xl font-bold text-slate-800">
                                    <AnimatedNumber :value="view.v" :format="twoDecimals" /><span class="text-xs text-slate-400"> /5</span>
                                </p>
                                <p v-if="view.vDelta !== null" class="text-[11px]" :class="deltaClass(view.vDelta, false)">
                                    <i :class="deltaIcon(view.vDelta)"></i> {{ signed(view.vDelta) }}
                                </p>
                            </div>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-slate-700">กลุ่มเปราะบางสำคัญ (คน)</h3>
                        <div class="mt-3 grid grid-cols-3 gap-2">
                            <div v-for="g in VULNERABLE" :key="g.key" class="rounded-xl border border-slate-100 p-2.5 text-center">
                                <span class="mx-auto flex h-8 w-8 items-center justify-center rounded-full text-sm" :class="g.color"
                                    ><i :class="g.icon"></i
                                ></span>
                                <p class="mt-1.5 text-base font-bold text-slate-800"><AnimatedNumber :value="view.counts[g.key]" /></p>
                                <p class="text-[11px] leading-tight text-slate-500">{{ g.label }}</p>
                                <p class="text-[10px] text-slate-400">
                                    <AnimatedNumber :value="pctOf(view.counts[g.key], view.population)" :format="percent" />
                                </p>
                            </div>
                        </div>
                    </div>

                    <div :class="card">
                        <h3 class="font-semibold text-slate-800">
                            ช่องว่างสำคัญ <span class="text-xs font-normal text-slate-400">(0–5, สูง = แย่)</span>
                        </h3>
                        <ul class="mt-4 space-y-3">
                            <li v-for="gap in gaps" :key="gap.key" class="grid grid-cols-[1fr_110px_70px] items-center gap-3">
                                <span class="text-sm text-slate-600">{{ gap.label }}</span>
                                <ScoreBar
                                    :value="view.groups[gap.key] ?? null"
                                    :max="5"
                                    :color="view.groups[gap.key] == null ? '#e2e8f0' : scoreColor(view.groups[gap.key]!, false)"
                                />
                                <span class="text-right text-sm">
                                    <span class="font-semibold text-slate-800"
                                        ><AnimatedNumber :value="view.groups[gap.key]" :format="oneDecimal"
                                    /></span>
                                    <span
                                        v-if="view.groupDelta(gap.key) !== null"
                                        class="ml-1 text-[11px]"
                                        :class="deltaClass(view.groupDelta(gap.key)!, false)"
                                        :title="`รอบก่อน ${(view.groups[gap.key]! - view.groupDelta(gap.key)!).toFixed(1)}`"
                                        ><i :class="deltaIcon(view.groupDelta(gap.key)!)"></i
                                    ></span>
                                </span>
                            </li>
                        </ul>
                    </div>

                    <div v-if="vOrigin" :class="card">
                        <h3 class="font-semibold text-slate-800">ที่มาของคะแนน V {{ hazardLabel[hazard] }}</h3>
                        <p class="text-[11px] text-slate-400">คะแนนแต่ละด้าน (0–5) × น้ำหนักจากเกณฑ์คะแนน</p>
                        <table class="mt-3 w-full text-sm">
                            <thead class="text-[11px] text-slate-400">
                                <tr>
                                    <th class="pb-1 text-left font-medium">องค์ประกอบ</th>
                                    <th class="pb-1 text-right font-medium">คะแนน</th>
                                    <th class="pb-1 text-right font-medium">× น้ำหนัก</th>
                                    <th class="pb-1 text-right font-medium">=</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="row in vOrigin.rows" :key="row.label">
                                    <td class="py-1.5 text-slate-600">{{ row.label }}</td>
                                    <td class="py-1.5 text-right font-mono text-slate-700">{{ formatScore(row.score, 2) }}</td>
                                    <td class="py-1.5 text-right font-mono text-slate-400">{{ row.weight.toFixed(2) }}</td>
                                    <td class="py-1.5 text-right font-mono text-slate-700">
                                        {{ row.product === null ? 'N/A' : row.product.toFixed(3) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-slate-200">
                                    <td colspan="3" class="pt-2 font-semibold text-slate-700">
                                        V {{ hazardLabel[hazard] }}
                                        <span v-if="vOrigin.renormalized" class="text-[11px] font-normal text-amber-600">
                                            (÷ {{ vOrigin.usedWeight.toFixed(2) }} เพราะมี N/A)</span
                                        >
                                    </td>
                                    <td class="pt-2 text-right font-mono font-bold text-slate-800">{{ formatScore(vOrigin.total, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                        <p v-if="!community" class="mt-2 text-[11px] text-slate-400">ระดับเมือง: คะแนนแต่ละด้านเป็นค่าเฉลี่ยถ่วงน้ำหนักตามประชากร</p>
                    </div>
                </div>

                <!-- C -->
                <div class="space-y-5">
                    <div :class="card">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"
                                ><i class="fa-solid fa-shield-heart"></i
                            ></span>
                            <h2 class="font-semibold text-slate-800">ความพร้อมรับมือ (Capacity)</h2>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-slate-700">
                            คะแนนรายด้าน <span class="text-xs font-normal text-slate-400">(0–5, สูง = พร้อม)</span>
                        </h3>
                        <TransitionGroup tag="ul" name="rank" class="relative mt-3 space-y-3">
                            <li v-for="cap in capacity" :key="cap.code" class="grid grid-cols-[1fr_100px_64px] items-center gap-3">
                                <span class="text-sm text-slate-600">{{ cap.label }}</span>
                                <ScoreBar :value="view.groups[cap.key] ?? null" :max="5" />
                                <span class="text-right text-sm">
                                    <span class="font-semibold text-slate-800"
                                        ><AnimatedNumber :value="view.groups[cap.key]" :format="oneDecimal"
                                    /></span>
                                    <span
                                        v-if="view.groupDelta(cap.key) !== null"
                                        class="ml-1 text-[11px]"
                                        :class="deltaClass(view.groupDelta(cap.key)!, true)"
                                        :title="`รอบก่อน ${(view.groups[cap.key]! - view.groupDelta(cap.key)!).toFixed(1)}`"
                                        ><i :class="deltaIcon(view.groupDelta(cap.key)!)"></i
                                    ></span>
                                </span>
                            </li>
                        </TransitionGroup>
                    </div>

                    <div :class="card">
                        <h3 class="font-semibold text-slate-800">คะแนนความพร้อมรวม (เฉพาะ{{ hazardLabel[hazard] }})</h3>
                        <div class="mt-3 flex items-center gap-5">
                            <div class="h-28 w-28 shrink-0"><ScoreRing :value="view.cRaw" :max="5" /></div>
                            <div class="text-sm text-slate-500">
                                <p>
                                    C_RAW = <span class="font-semibold text-slate-800">{{ formatScore(view.cRaw, 2) }}</span> / 5
                                </p>
                                <p v-if="view.cDelta !== null" :class="deltaClass(view.cDelta, true)">
                                    <i :class="deltaIcon(view.cDelta)" class="mr-0.5"></i>{{ signed(view.cDelta) }} จากรอบก่อน
                                </p>
                                <p v-else class="text-xs text-slate-400">ยังไม่มีข้อมูลเปรียบเทียบ</p>
                                <p class="mt-2 text-[11px] text-slate-400">
                                    ใช้ในสูตร HEVC เป็น C_HEVC = 1 + 0.8 × C_RAW = {{ view.cRaw === null ? '–' : (1 + 0.8 * view.cRaw).toFixed(2) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="cOrigin" :class="card">
                        <h3 class="font-semibold text-slate-800">ที่มาของคะแนน C {{ hazardLabel[hazard] }}</h3>
                        <p class="text-[11px] text-slate-400">คะแนนแต่ละด้าน (0–5) × น้ำหนักจากเกณฑ์คะแนน</p>
                        <table class="mt-3 w-full text-sm">
                            <thead class="text-[11px] text-slate-400">
                                <tr>
                                    <th class="pb-1 text-left font-medium">องค์ประกอบ</th>
                                    <th class="pb-1 text-right font-medium">คะแนน</th>
                                    <th class="pb-1 text-right font-medium">× น้ำหนัก</th>
                                    <th class="pb-1 text-right font-medium">=</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="row in cOrigin.rows" :key="row.label">
                                    <td class="py-1.5 text-slate-600">{{ row.label }}</td>
                                    <td class="py-1.5 text-right font-mono text-slate-700">{{ formatScore(row.score, 2) }}</td>
                                    <td class="py-1.5 text-right font-mono text-slate-400">{{ row.weight.toFixed(2) }}</td>
                                    <td class="py-1.5 text-right font-mono text-slate-700">
                                        {{ row.product === null ? 'N/A' : row.product.toFixed(3) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-slate-200">
                                    <td colspan="3" class="pt-2 font-semibold text-slate-700">
                                        C {{ hazardLabel[hazard] }}
                                        <span v-if="cOrigin.renormalized" class="text-[11px] font-normal text-amber-600">
                                            (÷ {{ cOrigin.usedWeight.toFixed(2) }} เพราะมี N/A)</span
                                        >
                                    </td>
                                    <td class="pt-2 text-right font-mono font-bold text-slate-800">{{ formatScore(cOrigin.total, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </template>
        <CalcTraceDialog :code="traceCode" :year="year" :initial-hazard="hazard" @close="traceCode = null" />
    </AdminLayout>
</template>

<style scoped>
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
