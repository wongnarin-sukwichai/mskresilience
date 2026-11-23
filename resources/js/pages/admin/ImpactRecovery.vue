<script setup lang="ts">
import ExportButtons from '@/components/admin/ExportButtons.vue';
import TorRef from '@/components/admin/TorRef.vue';
// หน้า 5 ผลกระทบและการฟื้นตัว (spec v5 §5.1–5.3). Data comes from ImpactRecoveryController. Owner decisions 2026-11-10:
// - A summary of the filtered events, plus a table; clicking a row opens one event.
// - Readiness = the community's RECOVERY_AVG on or before the event.
// - Items with no input column and the KPI table are left out (KPI → page 6).
// - L&D and finance are real values, never a score. The Recovery gap is shown neutrally
//   ("ต่างจากความพร้อมที่ประเมินไว้"), never as good/bad (spec §5.1).
import AnimatedNumber from '@/components/admin/AnimatedNumber.vue';
import LineChart from '@/components/admin/LineChart.vue';
import RadarChart from '@/components/admin/RadarChart.vue';
import ScatterChart from '@/components/admin/ScatterChart.vue';
import ScoreBar from '@/components/admin/ScoreBar.vue';
import { useInView } from '@/composables/useInView';
import { HAZARD_KEYS, formatThaiDate, hazardIcon, hazardLabel, levelOf, riskLevels } from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { HazardKey, ImpactEvent, RiskLevelKey } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    events: ImpactEvent[];
    budgets: Record<string, number>;
    hevcYears: number[];
    /** code → name + HEVC per fiscal year (RiskTrend), for the damage-vs-HEVC scatter. */
    hevc: Record<
        string,
        {
            name: string;
            years: Record<number, { multi: number | null; level: RiskLevelKey | null; hazards: Partial<Record<HazardKey, number | null>> }>;
        }
    >;
}>();

// ---------------------------------------------------------------------------------------------------------------
// Filters (all client-side: events are few)
// ---------------------------------------------------------------------------------------------------------------
const fiscalYears = computed(() => [...new Set(props.events.map((e) => e.fiscalYear))].sort((a, b) => b - a));
const year = ref<number | 'ALL'>(fiscalYears.value[0] ?? 'ALL');
const hazard = ref<'ALL' | HazardKey>('ALL');
const communityCode = ref<string>('ALL');
const communityOptions = computed(() =>
    [...new Map(props.events.map((e) => [e.code, e.name])).entries()].sort((a, b) => a[1].localeCompare(b[1], 'th')),
);

const filtered = computed(() =>
    props.events.filter(
        (e) =>
            (year.value === 'ALL' || e.fiscalYear === year.value) &&
            (hazard.value === 'ALL' || e.hazard === hazard.value) &&
            (communityCode.value === 'ALL' || e.code === communityCode.value),
    ),
);

// ---------------------------------------------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------------------------------------------
const sum = (values: (number | null)[]) => values.reduce<number>((s, v) => s + (v ?? 0), 0);
const avg = (values: (number | null)[]) => {
    const vs = values.filter((v): v is number => v !== null);
    return vs.length ? vs.reduce((s, v) => s + v, 0) / vs.length : null;
};

const loss = computed(() => {
    const e = filtered.value;
    return {
        houses: sum(e.map((x) => x.loss.houses)),
        injury: sum(e.map((x) => x.loss.injury)),
        death: sum(e.map((x) => x.loss.death)),
        damageThb: sum(e.map((x) => x.loss.damageThb)),
        recoveryDays: avg(e.map((x) => x.loss.recoveryDays)),
        repeat: sum(e.map((x) => x.loss.repeat)),
    };
});

const hazardCounts = computed(() => HAZARD_KEYS.map((h) => ({ h, n: filtered.value.filter((e) => e.hazard === h).length })).filter((x) => x.n > 0));
const affectedCommunities = computed(() => new Set(filtered.value.map((e) => e.code)).size);

const FINANCE = [
    { key: 'F2', label: 'ตอบสนองเหตุ', color: '#3b82f6' },
    { key: 'F3', label: 'ฟื้นฟู', color: '#22c55e' },
    { key: 'F4', label: 'ลดความเสี่ยง', color: '#f97316' },
    { key: 'F5', label: 'ช่วยเหลือ/เยียวยา', color: '#a855f7' },
] as const;
const finance = computed(() => {
    const total = sum(filtered.value.map((e) => e.finance.F1));
    return {
        total,
        parts: FINANCE.map((f) => {
            const value = sum(filtered.value.map((e) => e.finance[f.key]));
            // Spec §5.3: if F1 = 0 the share is N/A, not 0%.
            return { ...f, value, share: total > 0 ? (value / total) * 100 : null };
        }),
    };
});
const mismatchCount = computed(() => filtered.value.filter((e) => e.finance.importedF1Mismatch).length);

const budget = computed(() =>
    communityCode.value === 'ALL'
        ? { value: Object.values(props.budgets).reduce((s, v) => s + v, 0), label: 'รวมทุกชุมชน' }
        : { value: props.budgets[communityCode.value] ?? null, label: communityOptions.value.find(([c]) => c === communityCode.value)?.[1] ?? '' },
);

const R_LABELS: Record<string, string> = {
    R1: 'แผนฟื้นฟู',
    R2: 'ฟื้นฟูให้ดีกว่าเดิม',
    R3: 'ฟื้นฟูเศรษฐกิจและสังคม',
    R4: 'ฟื้นฟูกลุ่มเปราะบาง',
    R5: 'การเรียนรู้และปรับระบบ',
};
const R_KEYS = Object.keys(R_LABELS);

// Averages over the events that have both sides, so the two numbers describe the same events.
const recovery = computed(() => {
    const paired = filtered.value.filter((e) => e.readiness?.avg != null && e.observed.avg != null);
    const before = avg(paired.map((e) => e.readiness!.avg));
    const after = avg(paired.map((e) => e.observed.avg));
    return {
        count: paired.length,
        before,
        after,
        gap: before !== null && after !== null ? after - before : null,
        items: R_KEYS.map((k) => {
            const both = filtered.value.filter((e) => e.readiness?.items[k] != null && e.observed.items[k] != null);
            return {
                key: k,
                label: R_LABELS[k],
                before: avg(both.map((e) => e.readiness!.items[k])),
                after: avg(both.map((e) => e.observed.items[k])),
            };
        }),
    };
});

// ---------------------------------------------------------------------------------------------------------------
// Loss trend over time (TOR 4.1.1(5)-1): every year, so the year filter doesn't apply; hazard/community do.
// ---------------------------------------------------------------------------------------------------------------
const TREND_METRICS = [
    { key: 'damage', label: 'มูลค่าความเสียหาย (ล้านบาท)', value: (e: ImpactEvent) => (e.loss.damageThb ?? 0) / 1_000_000 },
    { key: 'houses', label: 'บ้านเรือนเสียหาย (หลัง)', value: (e: ImpactEvent) => e.loss.houses ?? 0 },
    { key: 'injury', label: 'ผู้บาดเจ็บ (คน)', value: (e: ImpactEvent) => e.loss.injury ?? 0 },
    { key: 'events', label: 'จำนวนเหตุการณ์', value: () => 1 },
] as const;
const trendMetric = ref<(typeof TREND_METRICS)[number]['key']>('damage');
const THAI_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const lossTrend = computed(() => {
    const events = props.events.filter(
        (e) => (hazard.value === 'ALL' || e.hazard === hazard.value) && (communityCode.value === 'ALL' || e.code === communityCode.value),
    );
    const all = props.events.map((e) => e.start.slice(0, 7)).sort();
    if (!all.length) return { categories: [] as string[], values: [] as number[] };
    // Every month from the first to the last event in the system, so gaps show as zero months.
    const months: string[] = [];
    let [y, m] = all[0].split('-').map(Number);
    const [ly, lm] = all[all.length - 1].split('-').map(Number);
    while (y < ly || (y === ly && m <= lm)) {
        months.push(`${y}-${String(m).padStart(2, '0')}`);
        m++;
        if (m > 12) {
            m = 1;
            y++;
        }
    }
    const metric = TREND_METRICS.find((t) => t.key === trendMetric.value)!;
    const totals = new Map(months.map((k) => [k, 0]));
    for (const e of events) totals.set(e.start.slice(0, 7), (totals.get(e.start.slice(0, 7)) ?? 0) + metric.value(e));
    return {
        categories: months.map((k) => `${THAI_MONTHS[Number(k.slice(5)) - 1]} ${String(Number(k.slice(0, 4)) + 543).slice(2)}`),
        values: months.map((k) => totals.get(k) ?? 0),
    };
});

// ---------------------------------------------------------------------------------------------------------------
// Damage vs HEVC (TOR 4.1.1(5)-2): each community's HEVC in the chosen year (the latest year for ทั้งหมด) against
// the damage of the filtered events. Communities without events sit on the zero line.
// ---------------------------------------------------------------------------------------------------------------
const scatterYear = computed(() => (year.value === 'ALL' ? Math.max(...props.hevcYears) : year.value));
const scatter = computed(() => {
    const damage = new Map<string, number>();
    for (const e of filtered.value) damage.set(e.code, (damage.get(e.code) ?? 0) + (e.loss.damageThb ?? 0));
    const points = Object.entries(props.hevc)
        .map(([code, c]) => {
            const p = c.years[scatterYear.value];
            const x = p ? (hazard.value === 'ALL' ? p.multi : (p.hazards[hazard.value] ?? null)) : null;
            return x === null
                ? null
                : {
                      id: code,
                      label: `${code} ${c.name}`,
                      x,
                      y: damage.get(code) ?? 0,
                      color: riskLevels[levelOf(x)].color,
                      emphasis: code === communityCode.value,
                  };
        })
        .filter((p): p is NonNullable<typeof p> => p !== null);
    const high = points.filter((p) => p.x >= 50);
    const rest = points.filter((p) => p.x < 50);
    const mean = (ps: typeof points) => (ps.length ? ps.reduce((s, p) => s + p.y, 0) / ps.length : null);
    return { points, highMean: mean(high), restMean: mean(rest), highCount: high.length };
});

// ---------------------------------------------------------------------------------------------------------------
// Event detail
// ---------------------------------------------------------------------------------------------------------------
const openId = ref<string | null>(null);
const openEvent = computed(() => props.events.find((e) => e.id === openId.value) ?? null);

// Finance bar animates once in view (stacked widths via CSS transition).
const financeEl = ref<HTMLElement | null>(null);
const financeInView = useInView(financeEl, 0.4);

// ---------------------------------------------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------------------------------------------
function baht(v: number): string {
    return v >= 1_000_000 ? `${(v / 1_000_000).toFixed(2)} ล้านบาท` : `${Math.round(v).toLocaleString('th-TH')} บาท`;
}
const millions = (v: number) => (v / 1_000_000).toFixed(2);
const oneDecimal = (v: number) => v.toFixed(1);
const signed = (v: number) => `${v > 0 ? '+' : ''}${v.toFixed(1)}`;
const num = (v: number | null) => (v === null ? '–' : v.toLocaleString('th-TH'));

const card = 'rounded-2xl border border-slate-100 bg-white p-5 shadow-sm';
const selectClass =
    'rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="ผลกระทบและการฟื้นตัว" />

    <AdminLayout title="ผลกระทบและการฟื้นตัว">
        <!-- Filters (sticky) -->
        <div
            class="sticky -top-4 z-20 -mx-4 mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200/70 bg-slate-50/90 px-4 py-3 backdrop-blur lg:-top-6 lg:-mx-6 lg:px-6"
        >
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ช่วงข้อมูล (วันเริ่มเหตุการณ์)</span>
                <select v-model="year" :class="selectClass">
                    <option v-for="y in fiscalYears" :key="y" :value="y">ปีงบประมาณ {{ y }}{{ y === fiscalYears[0] ? ' (ล่าสุด)' : '' }}</option>
                    <option value="ALL">ทั้งหมด</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ภัย</span>
                <select v-model="hazard" :class="selectClass">
                    <option value="ALL">ทุกภัย</option>
                    <option v-for="h in HAZARD_KEYS" :key="h" :value="h">{{ hazardLabel[h] }}</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ชุมชน</span>
                <select v-model="communityCode" :class="selectClass">
                    <option value="ALL">ทุกชุมชน</option>
                    <option v-for="[code, name] in communityOptions" :key="code" :value="code">{{ name }}</option>
                </select>
            </label>
            <div v-if="events.length" class="ml-auto flex flex-wrap items-center gap-2 pb-1 print:hidden">
                <Link
                    v-if="communityCode !== 'ALL'"
                    :href="route('reports.funding', communityCode)"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-blue-200 bg-white px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-50"
                >
                    <i class="fa-solid fa-file-invoice-dollar"></i>รายงานประกอบการของบประมาณ
                </Link>
                <ExportButtons
                    :excel-href="
                        route('export.events', {
                            year: year === 'ALL' ? undefined : year,
                            hazard: hazard === 'ALL' ? undefined : hazard,
                            community: communityCode === 'ALL' ? undefined : communityCode,
                        })
                    "
                />
            </div>
        </div>

        <div v-if="!events.length" class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            <i class="fa-solid fa-database mb-3 block text-3xl text-slate-300"></i>
            ยังไม่มีข้อมูลเหตุการณ์ — นำเข้าที่เมนู "นำเข้าข้อมูล" กลุ่มงานป้องกันภัยเหตุการณ์
        </div>

        <template v-else>
            <p class="mb-4 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <span
                    ><span class="font-semibold text-slate-700"><AnimatedNumber :value="filtered.length" /></span> เหตุการณ์ ·
                    <AnimatedNumber :value="affectedCommunities" /> ชุมชน</span
                >
                <span v-for="x in hazardCounts" :key="x.h" class="rounded-full bg-white px-2.5 py-0.5 text-slate-600 ring-1 ring-slate-200">
                    <i :class="hazardIcon[x.h]" class="mr-1"></i>{{ hazardLabel[x.h] }} {{ x.n }}
                </span>
            </p>

            <div v-if="!filtered.length" class="rounded-2xl border border-slate-100 bg-white p-8 text-center text-sm text-slate-400 shadow-sm">
                ไม่มีเหตุการณ์ตามตัวกรองที่เลือก
            </div>

            <template v-else>
                <div class="grid gap-5 xl:grid-cols-2">
                    <!-- L&D -->
                    <div :class="card">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-red-50 text-red-500"
                                ><i class="fa-solid fa-house-crack"></i
                            ></span>
                            <div>
                                <h2 class="font-semibold text-slate-800">ความสูญเสียและความเสียหาย (Loss & Damage)<TorRef :refs="['4.1.1(5)']" /></h2>
                                <p class="text-xs text-slate-400">ค่าจริงรวมของเหตุการณ์ที่เลือก ไม่แปลงเป็นคะแนน</p>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">บ้านเรือนเสียหาย</p>
                                <p class="text-2xl font-bold text-slate-800"><AnimatedNumber :value="loss.houses" /></p>
                                <p class="text-[11px] text-slate-400">หลัง</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">ผู้บาดเจ็บ</p>
                                <p class="text-2xl font-bold text-slate-800"><AnimatedNumber :value="loss.injury" /></p>
                                <p class="text-[11px] text-slate-400">คน</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">ผู้เสียชีวิต</p>
                                <p class="text-2xl font-bold text-slate-800"><AnimatedNumber :value="loss.death" /></p>
                                <p class="text-[11px] text-slate-400">คน</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">มูลค่าความเสียหาย</p>
                                <p class="text-2xl font-bold text-slate-800"><AnimatedNumber :value="loss.damageThb" :format="millions" /></p>
                                <p class="text-[11px] text-slate-400">ล้านบาท</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">ระยะเวลาฟื้นตัว (เฉลี่ย)</p>
                                <p class="text-2xl font-bold text-slate-800"><AnimatedNumber :value="loss.recoveryDays" :format="oneDecimal" /></p>
                                <p class="text-[11px] text-slate-400">วัน</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">ความเสียหายซ้ำ</p>
                                <p class="text-2xl font-bold text-slate-800"><AnimatedNumber :value="loss.repeat" /></p>
                                <p class="text-[11px] text-slate-400">ครั้ง</p>
                            </div>
                        </div>
                    </div>

                    <!-- Recovery -->
                    <div :class="card">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-600"
                                ><i class="fa-solid fa-rotate"></i
                            ></span>
                            <div>
                                <h2 class="font-semibold text-slate-800">การฟื้นฟู (Recovery)<TorRef :refs="['4.1.1(4)']" /></h2>
                                <p class="text-xs text-slate-400">
                                    ความพร้อมก่อนเหตุ (R1–R5) เทียบผลการฟื้นฟูจริงหลังเหตุ · {{ recovery.count }} เหตุการณ์
                                </p>
                            </div>
                        </div>
                        <div v-if="recovery.count" class="mt-4 grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">ความพร้อมก่อนเหตุ</p>
                                <p class="text-2xl font-bold text-slate-800">
                                    <AnimatedNumber :value="recovery.before" :format="oneDecimal" /><span class="text-xs text-slate-400"> /5</span>
                                </p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-xs text-slate-500">ผลจริงหลังเหตุ</p>
                                <p class="text-2xl font-bold text-slate-800">
                                    <AnimatedNumber :value="recovery.after" :format="oneDecimal" /><span class="text-xs text-slate-400"> /5</span>
                                </p>
                            </div>
                            <div class="rounded-xl bg-blue-50 p-3">
                                <p class="text-xs text-blue-700">ต่างจากความพร้อมที่ประเมินไว้</p>
                                <p class="text-2xl font-bold text-blue-800"><AnimatedNumber :value="recovery.gap" :format="signed" /></p>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-400">ไม่มีเหตุการณ์ที่มีทั้งข้อมูลความพร้อมก่อนเหตุและผลการฟื้นฟูจริง</p>
                        <!-- R1–R5 radar: readiness before the event vs observed after (TOR 4.1.1(4), คุณลักษณะ 2.5-1) -->
                        <div v-if="recovery.count" class="mt-3">
                            <RadarChart
                                :axes="recovery.items.map((r) => ({ key: r.key, label: r.label }))"
                                :series="[
                                    { label: 'ความพร้อมก่อนเหตุ', color: '#94a3b8', values: recovery.items.map((r) => r.before), dashed: true },
                                    { label: 'ผลจริงหลังเหตุ', color: '#3b82f6', values: recovery.items.map((r) => r.after) },
                                ]"
                            />
                            <p class="mt-1 text-center text-[11px] text-slate-400">
                                <TorRef :refs="['คุณลักษณะ 2.5-1']" /> ด้านที่หดเข้าหาศูนย์กลาง = จุดอ่อนของการฟื้นฟู
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Loss trend over time (TOR 4.1.1(5)-1, คุณลักษณะ 2.5-2) -->
                <div :class="[card, 'mt-5']">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-slate-800">
                                แนวโน้มความเสียหายย้อนหลัง<TorRef :refs="['4.1.1(5)-1', 'คุณลักษณะ 2.5-2']" />
                            </h2>
                            <p class="text-xs text-slate-400">
                                รวมรายเดือนตลอดทุกปีที่มีข้อมูล ·
                                {{ hazard === 'ALL' ? 'ทุกภัย' : hazardLabel[hazard] }} ·
                                {{ communityCode === 'ALL' ? 'ทุกชุมชน' : communityOptions.find(([c]) => c === communityCode)?.[1] }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="t in TREND_METRICS"
                                :key="t.key"
                                type="button"
                                class="rounded-lg px-2.5 py-1 text-xs"
                                :class="trendMetric === t.key ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                @click="trendMetric = t.key"
                            >
                                {{ t.label }}
                            </button>
                        </div>
                    </div>
                    <LineChart
                        v-if="lossTrend.categories.length"
                        class="mt-3"
                        :categories="lossTrend.categories"
                        :series="[{ label: TREND_METRICS.find((t) => t.key === trendMetric)!.label, color: '#ef4444', values: lossTrend.values }]"
                    />
                    <p v-else class="mt-3 text-sm text-slate-400">ยังไม่มีข้อมูลเหตุการณ์</p>
                </div>

                <!-- Damage vs HEVC (TOR 4.1.1(5)-2) -->
                <div :class="[card, 'mt-5']">
                    <h2 class="font-semibold text-slate-800">ความเสียหายจริงเทียบกับค่า HEVC<TorRef :refs="['4.1.1(5)-2']" /></h2>
                    <p class="text-xs text-slate-400">
                        แต่ละจุดคือหนึ่งชุมชน · แกนนอน = ค่า HEVC {{ hazard === 'ALL' ? 'ความเสี่ยงรวม' : hazardLabel[hazard] }} ปีงบ
                        {{ scatterYear }} · แกนตั้ง = มูลค่าความเสียหายจากเหตุการณ์ที่กรองไว้ · คลิกจุดเพื่อดูเฉพาะชุมชนนั้น
                    </p>
                    <ScatterChart
                        class="mt-3"
                        :points="scatter.points"
                        x-label="ค่า HEVC (0–100)"
                        y-label="ความเสียหาย (บาท)"
                        :x-max="100"
                        :split-x="50"
                        :format-y="(v: number) => (v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)} ล้าน` : Math.round(v).toLocaleString('th-TH'))"
                        @select="(id: string) => (communityCode = communityCode === id ? 'ALL' : id)"
                    />
                    <p
                        v-if="scatter.highMean !== null && scatter.restMean !== null"
                        class="mt-2 rounded-xl bg-slate-50 px-4 py-2.5 text-xs text-slate-600"
                    >
                        <i class="fa-solid fa-chart-simple mr-1 text-slate-400"></i>
                        ชุมชนที่ HEVC ตั้งแต่ 50 ขึ้นไป ({{ scatter.highCount }} ชุมชน) เสียหายเฉลี่ย <b>{{ baht(scatter.highMean) }}</b> ต่อชุมชน ·
                        ชุมชนอื่นเฉลี่ย
                        <b>{{ baht(scatter.restMean) }}</b>
                    </p>
                </div>

                <!-- Finance -->
                <div ref="financeEl" :class="[card, 'mt-5']">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-600"
                                ><i class="fa-solid fa-coins"></i
                            ></span>
                            <div>
                                <h2 class="font-semibold text-slate-800">การเงินจากเหตุการณ์</h2>
                                <p class="text-xs text-slate-400">F1 = F2 + F3 + F4 + F5</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-500">งบประมาณ/ค่าใช้จ่ายรวม (F1)</p>
                            <p class="text-3xl font-bold text-slate-800">
                                <AnimatedNumber :value="finance.total" :format="millions" />
                                <span class="text-sm font-medium text-slate-400">ล้านบาท</span>
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 flex h-8 w-full overflow-hidden rounded-xl bg-slate-100">
                        <div
                            v-for="p in finance.parts"
                            :key="p.key"
                            class="flex items-center justify-center overflow-hidden text-xs font-semibold text-white transition-[width] duration-1000 ease-out motion-reduce:transition-none"
                            :style="{ width: financeInView && p.share !== null ? `${p.share}%` : '0%', background: p.color }"
                            :title="`${p.key} ${p.label}`"
                        >
                            <span v-if="p.share !== null && p.share >= 8">{{ p.share.toFixed(0) }}%</span>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="p in finance.parts" :key="p.key" class="flex items-start gap-2">
                            <span class="mt-1 h-3 w-3 shrink-0 rounded-sm" :style="{ background: p.color }"></span>
                            <div>
                                <p class="text-xs text-slate-500">{{ p.key }} {{ p.label }}</p>
                                <p class="font-semibold text-slate-800"><AnimatedNumber :value="p.value" :format="baht" /></p>
                                <p class="text-[11px] text-slate-400">{{ p.share === null ? 'N/A' : `${p.share.toFixed(1)}%` }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
                        <span>
                            งบด้านการจัดการภัยเฉลี่ย 3 ปี ({{ budget.label }}):
                            <span class="font-semibold text-slate-700">{{ budget.value === null ? 'ไม่มีข้อมูล' : baht(budget.value) }}</span>
                        </span>
                        <span v-if="mismatchCount" class="text-amber-600">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ mismatchCount }} เหตุการณ์มี F1 ที่นำเข้าไม่เท่ากับ F2+F3+F4+F5
                            (ใช้ผลรวม)
                        </span>
                    </div>
                </div>

                <!-- Events table -->
                <div :class="[card, 'mt-5 p-0']">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h2 class="font-semibold text-slate-800">รายการเหตุการณ์</h2>
                        <p class="text-xs text-slate-400">เรียงจากล่าสุด · คลิกแถวเพื่อดูรายละเอียด</p>
                    </div>
                    <div class="custom-scrollbar max-h-[480px] overflow-auto">
                        <table class="w-full min-w-[820px] text-left text-sm">
                            <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">วันที่เริ่ม</th>
                                    <th class="px-4 py-2.5 font-medium">ชุมชน</th>
                                    <th class="px-4 py-2.5 font-medium">ภัย</th>
                                    <th class="px-4 py-2.5 text-right font-medium">บ้านเสียหาย</th>
                                    <th class="px-4 py-2.5 text-right font-medium">บาดเจ็บ / เสียชีวิต</th>
                                    <th class="px-4 py-2.5 text-right font-medium">มูลค่าเสียหาย</th>
                                    <th class="px-4 py-2.5 text-right font-medium">F1</th>
                                    <th class="px-4 py-2.5 text-right font-medium">การฟื้นฟู ก่อน → หลัง</th>
                                </tr>
                            </thead>
                            <TransitionGroup tag="tbody" name="rank" class="divide-y divide-slate-100">
                                <tr v-for="e in filtered" :key="e.id" class="cursor-pointer hover:bg-blue-50/50" @click="openId = e.id">
                                    <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ formatThaiDate(e.start) }}</td>
                                    <td class="px-4 py-2.5 font-medium text-slate-700">{{ e.name }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">
                                        <i :class="hazardIcon[e.hazard]" class="mr-1"></i>{{ hazardLabel[e.hazard] }}
                                        <span v-if="e.multi" class="ml-1 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700"
                                            >หลายภัย</span
                                        >
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono">{{ num(e.loss.houses) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono">{{ num(e.loss.injury) }} / {{ num(e.loss.death) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono">{{ e.loss.damageThb === null ? '–' : baht(e.loss.damageThb) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono">{{ e.finance.F1 === null ? '–' : baht(e.finance.F1) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono text-slate-600">
                                        {{ e.readiness?.avg?.toFixed(1) ?? '–' }} → {{ e.observed.avg?.toFixed(1) ?? '–' }}
                                    </td>
                                </tr>
                            </TransitionGroup>
                        </table>
                    </div>
                </div>
            </template>
        </template>

        <!-- Event detail -->
        <Transition name="fade">
            <div
                v-if="openEvent"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
                @click.self="openId = null"
            >
                <div class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                    <div class="flex items-start justify-between gap-3 bg-blue-600 px-6 py-4 text-white">
                        <div>
                            <h3 class="text-base font-semibold">
                                <i :class="hazardIcon[openEvent.hazard]" class="mr-1"></i>{{ hazardLabel[openEvent.hazard] }} · {{ openEvent.name }}
                            </h3>
                            <p class="text-xs text-blue-100">
                                {{ openEvent.id }} · {{ formatThaiDate(openEvent.start)
                                }}{{ openEvent.end ? ` – ${formatThaiDate(openEvent.end)}` : '' }}
                                <template v-if="openEvent.related.length > 1">
                                    · ภัยที่เกี่ยวข้อง {{ openEvent.related.map((h) => hazardLabel[h]).join(', ') }}</template
                                >
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                            @click="openId = null"
                        >
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="custom-scrollbar space-y-5 overflow-y-auto p-6 text-sm">
                        <p v-if="openEvent.description" class="rounded-xl bg-slate-50 p-3 text-slate-600">{{ openEvent.description }}</p>

                        <div>
                            <h4 class="mb-2 font-semibold text-slate-700">ความสูญเสียและความเสียหาย</h4>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div
                                    v-for="item in [
                                        { l: 'บ้านเสียหาย (หลัง)', v: num(openEvent.loss.houses) },
                                        { l: 'ผู้บาดเจ็บ (คน)', v: num(openEvent.loss.injury) },
                                        { l: 'ผู้เสียชีวิต (คน)', v: num(openEvent.loss.death) },
                                        { l: 'มูลค่าความเสียหาย', v: openEvent.loss.damageThb === null ? '–' : baht(openEvent.loss.damageThb) },
                                        { l: 'ระยะเวลาฟื้นตัว (วัน)', v: num(openEvent.loss.recoveryDays) },
                                        { l: 'ความเสียหายซ้ำ (ครั้ง)', v: num(openEvent.loss.repeat) },
                                    ]"
                                    :key="item.l"
                                    class="rounded-xl border border-slate-100 p-2.5"
                                >
                                    <p class="text-[11px] text-slate-400">{{ item.l }}</p>
                                    <p class="font-semibold text-slate-800">{{ item.v }}</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="mb-2 font-semibold text-slate-700">
                                การเงิน · F1 {{ openEvent.finance.F1 === null ? '–' : baht(openEvent.finance.F1) }}
                            </h4>
                            <ul class="space-y-1.5">
                                <li v-for="f in FINANCE" :key="f.key" class="grid grid-cols-[150px_1fr_110px] items-center gap-3 text-xs">
                                    <span class="text-slate-600"
                                        ><span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: f.color }"></span>{{ f.key }}
                                        {{ f.label }}</span
                                    >
                                    <ScoreBar :value="openEvent.finance[f.key]" :max="openEvent.finance.F1 || 1" :color="f.color" />
                                    <span class="text-right font-mono text-slate-700">{{
                                        openEvent.finance[f.key] === null ? '–' : baht(openEvent.finance[f.key]!)
                                    }}</span>
                                </li>
                            </ul>
                            <p v-if="openEvent.finance.importedF1Mismatch" class="mt-2 text-xs text-amber-600">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>F1 ที่นำเข้าไม่เท่ากับ F2+F3+F4+F5 ระบบใช้ผลรวม
                            </p>
                        </div>

                        <div>
                            <h4 class="mb-2 font-semibold text-slate-700">การฟื้นฟู: ความพร้อมก่อนเหตุ เทียบผลจริงหลังเหตุ</h4>
                            <p v-if="!openEvent.readiness" class="mb-2 text-xs text-slate-400">ไม่มีข้อมูลความพร้อมก่อนเหตุของชุมชนนี้</p>
                            <p v-else class="mb-2 text-xs text-slate-400">
                                ความพร้อมจากข้อมูลวันที่ {{ formatThaiDate(openEvent.readiness.date) }} · {{ openEvent.criteriaSet }}
                            </p>
                            <table class="w-full text-xs">
                                <thead class="text-slate-400">
                                    <tr>
                                        <th class="pb-1 text-left font-medium">ด้าน</th>
                                        <th class="pb-1 text-right font-medium">ก่อนเหตุ</th>
                                        <th class="pb-1 text-right font-medium">หลังเหตุ (จริง)</th>
                                        <th class="pb-1 text-right font-medium">ต่างกัน</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <tr v-for="k in R_KEYS" :key="k">
                                        <td class="py-1.5 text-slate-600">{{ k }} {{ R_LABELS[k] }}</td>
                                        <td class="py-1.5 text-right font-mono">{{ openEvent.readiness?.items[k] ?? '–' }}</td>
                                        <td class="py-1.5 text-right font-mono">{{ openEvent.observed.items[k] ?? '–' }}</td>
                                        <td class="py-1.5 text-right font-mono text-blue-700">
                                            {{
                                                openEvent.readiness?.items[k] != null && openEvent.observed.items[k] != null
                                                    ? signed(openEvent.observed.items[k]! - openEvent.readiness.items[k]!)
                                                    : '–'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-slate-200 font-semibold">
                                        <td class="pt-2 text-slate-700">เฉลี่ยถ่วงน้ำหนัก</td>
                                        <td class="pt-2 text-right font-mono">{{ openEvent.readiness?.avg?.toFixed(2) ?? '–' }}</td>
                                        <td class="pt-2 text-right font-mono">{{ openEvent.observed.avg?.toFixed(2) ?? '–' }}</td>
                                        <td class="pt-2 text-right font-mono text-blue-700">
                                            {{
                                                openEvent.readiness?.avg != null && openEvent.observed.avg != null
                                                    ? signed(openEvent.observed.avg - openEvent.readiness.avg)
                                                    : '–'
                                            }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="mt-2 text-[11px] text-slate-400">ผลต่างแสดง "ต่างจากความพร้อมที่ประเมินไว้" ไม่ได้ตีความว่าดีหรือแย่</p>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </AdminLayout>
</template>

<style scoped>
.rank-move,
.rank-enter-active,
.rank-leave-active {
    transition: all 0.4s ease;
}
.rank-enter-from,
.rank-leave-to {
    opacity: 0;
}
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .rank-move,
    .rank-enter-active,
    .rank-leave-active,
    .fade-enter-active,
    .fade-leave-active {
        transition: none;
    }
}
</style>
