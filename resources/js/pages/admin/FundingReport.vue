<script setup lang="ts">
import ExportButtons from '@/components/admin/ExportButtons.vue';
import LineChart from '@/components/admin/LineChart.vue';
import RadarChart from '@/components/admin/RadarChart.vue';
import TorRef from '@/components/admin/TorRef.vue';
import { HAZARD_KEYS, RISK_LEVEL_KEYS, formatThaiDate, hazardLabel, riskLevels } from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { HazardKey, RiskLevelKey } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// รายงานหลักฐานประกอบการเสนอของบประมาณรายชุมชน (FundingReportController; TOR 4.1.1(5)-3). A document-style page
// meant to be saved as PDF and attached to a budget request: every number comes from the system, with its source.
type R = 'R1' | 'R2' | 'R3' | 'R4' | 'R5';
const props = defineProps<{
    community: {
        code: string;
        name: string;
        status: string;
        dataDate: string | null;
        criteriaSet: string | null;
        population: number | null;
        households: number | null;
        areaKm2: number | null;
        multi: { hevc: number | null; level: RiskLevelKey | null; dominant: HazardKey | null } | null;
        hazards: Record<
            HazardKey,
            {
                hevc: number | null;
                level: RiskLevelKey | null;
                e: number | null;
                ePercent: number | null;
                v: number | null;
                vPeople: number | null;
                vSystem: number | null;
                cRaw: number | null;
            }
        > | null;
        recovery: number | null;
        recoveryItems: Record<R, number | null>;
        budget: number | null;
        budgetPerPerson: number | null;
    };
    city: {
        hevc: number | null;
        budgetPerPersonMedian: number | null;
        recoveryItems: Record<R, number | null>;
        rank: number | false;
        scored: number;
    };
    trend: { year: number; multi: number | null; stale: boolean }[];
    flags: { label: string; reason: string; icon: string }[];
    lossByYear: {
        year: number;
        count: number;
        houses: number;
        injury: number;
        death: number;
        damage: number;
        f2: number;
        f3: number;
        f4: number;
        f5: number;
    }[];
    events: {
        id: string;
        start: string;
        hazard: HazardKey;
        houses: number | null;
        injury: number | null;
        death: number | null;
        damage: number | null;
        description: string | null;
    }[];
    mcr: { coverage: number; essentials: Record<string, { status: string; sentence: string }> };
    essentialLabels: Record<string, string>;
}>();

const R_AXES = [
    { key: 'R1', label: 'แผนฟื้นฟู' },
    { key: 'R2', label: 'Build Back Better' },
    { key: 'R3', label: 'เศรษฐกิจและสังคม' },
    { key: 'R4', label: 'กลุ่มเปราะบาง' },
    { key: 'R5', label: 'ถอดบทเรียน' },
] as const;
const bands = RISK_LEVEL_KEYS.map((k, i) => ({ from: [0, 25, 50, 75][i], to: [25, 50, 75, 100][i], color: riskLevels[k].color }));
const baht = (v: number | null) => (v === null ? '–' : Math.round(v).toLocaleString('th-TH'));
const one = (v: number | null | undefined) => (v === null || v === undefined ? '–' : v.toFixed(1));
const totals = computed(() =>
    props.lossByYear.reduce(
        (t, y) => ({
            count: t.count + y.count,
            damage: t.damage + y.damage,
            houses: t.houses + y.houses,
            spend: t.spend + y.f2 + y.f3 + y.f4 + y.f5,
        }),
        { count: 0, damage: 0, houses: 0, spend: 0 },
    ),
);
const budgetRatio = computed(() =>
    props.community.budgetPerPerson !== null && props.city.budgetPerPersonMedian
        ? props.community.budgetPerPerson / props.city.budgetPerPersonMedian
        : null,
);
const today = new Date().toISOString().slice(0, 10);
const section = 'print-keep mt-6';
</script>

<template>
    <Head :title="`รายงานหลักฐานประกอบการเสนอของบประมาณ ${community.name}`" />

    <AdminLayout title="รายงานหลักฐานประกอบการเสนอของบประมาณ">
        <div class="mx-auto max-w-4xl rounded-2xl border border-slate-100 bg-white p-8 shadow-sm print:border-0 print:p-0 print:shadow-none">
            <div class="flex flex-wrap items-start justify-between gap-3 print:hidden">
                <Link :href="route('community-map.index', { community: community.code })" class="text-sm text-blue-600 hover:underline">
                    <i class="fa-solid fa-arrow-left mr-1"></i>กลับไปแผนที่ชุมชน
                </Link>
                <ExportButtons :excel-href="route('export.events', { community: community.code })" />
            </div>

            <h1 class="mt-4 text-2xl font-bold text-slate-900">รายงานหลักฐานประกอบการเสนอของบประมาณ<TorRef :refs="['4.1.1(5)-3']" /></h1>
            <p class="text-lg font-semibold text-slate-700">ชุมชน{{ community.name }} ({{ community.code }})</p>
            <p class="mt-1 text-xs text-slate-500">
                ข้อมูล ณ {{ formatThaiDate(community.dataDate) }} · ชุดเกณฑ์ {{ community.criteriaSet ?? '–' }} · จัดทำ {{ formatThaiDate(today) }} ·
                ที่มา: ระบบบริหารข้อมูลความเสี่ยงและความเปราะบางของชุมชน เทศบาลเมืองมหาสารคาม
            </p>

            <!-- 1. Profile + risk -->
            <section :class="section">
                <h2 class="border-b border-slate-200 pb-1 font-semibold text-slate-800">1. ข้อมูลชุมชนและระดับความเสี่ยง</h2>
                <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">ประชากร</p>
                        <p class="font-bold">{{ community.population?.toLocaleString('th-TH') ?? '–' }} คน</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">ครัวเรือน</p>
                        <p class="font-bold">{{ community.households?.toLocaleString('th-TH') ?? '–' }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">พื้นที่</p>
                        <p class="font-bold">{{ community.areaKm2 ?? '–' }} ตร.กม.</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">HEVC ความเสี่ยงรวม</p>
                        <p class="font-bold">
                            {{ one(community.multi?.hevc) }}
                            <span
                                v-if="community.multi?.level"
                                class="ml-1 rounded px-1.5 text-xs"
                                :class="riskLevels[community.multi.level].badge"
                                >{{ riskLevels[community.multi.level].label }}</span
                            >
                        </p>
                        <p v-if="city.rank !== false" class="text-[11px] text-slate-500">
                            อันดับ {{ city.rank + 1 }} จาก {{ city.scored }} ชุมชน (เมือง {{ one(city.hevc) }})
                        </p>
                    </div>
                </div>
                <table v-if="community.hazards" class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr class="border-b border-slate-100">
                            <th class="py-1.5">ภัย</th>
                            <th class="py-1.5 text-right">HEVC</th>
                            <th class="py-1.5 text-right">ประชากรเปิดรับ</th>
                            <th class="py-1.5 text-right">V ด้านคน</th>
                            <th class="py-1.5 text-right">V ด้านระบบ</th>
                            <th class="py-1.5 text-right">ความพร้อม C</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="h in HAZARD_KEYS"
                            :key="h"
                            class="border-b border-slate-50"
                            :class="{ 'font-semibold': h === community.multi?.dominant }"
                        >
                            <td class="py-1.5">
                                {{ hazardLabel[h] }}<span v-if="h === community.multi?.dominant" class="text-xs text-red-600"> (ภัยเด่น)</span>
                            </td>
                            <td class="py-1.5 text-right">{{ one(community.hazards[h].hevc) }}</td>
                            <td class="py-1.5 text-right">
                                {{ community.hazards[h].ePercent === null ? '–' : community.hazards[h].ePercent!.toFixed(1) + '%' }}
                            </td>
                            <td class="py-1.5 text-right">{{ one(community.hazards[h].vPeople) }}</td>
                            <td class="py-1.5 text-right">{{ one(community.hazards[h].vSystem) }}</td>
                            <td class="py-1.5 text-right">{{ one(community.hazards[h].cRaw) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-1 text-[11px] text-slate-400">V และ C เป็นคะแนน 0–5 (V สูง = เปราะบางมาก, C สูง = พร้อมรับมือมาก)</p>
            </section>

            <!-- 2. Trend -->
            <section v-if="trend.length > 1" :class="section">
                <h2 class="border-b border-slate-200 pb-1 font-semibold text-slate-800">2. แนวโน้มความเสี่ยงรายปีงบประมาณ</h2>
                <LineChart
                    class="mt-2"
                    :categories="trend.map((t) => `ปีงบ ${t.year}`)"
                    :series="[{ label: 'HEVC', color: '#2563eb', values: trend.map((t) => t.multi), hollow: trend.map((t) => t.stale) }]"
                    :max="100"
                    :bands="bands"
                    :height="190"
                />
            </section>

            <!-- 3. Losses and spending -->
            <section :class="section">
                <h2 class="border-b border-slate-200 pb-1 font-semibold text-slate-800">3. ความสูญเสียและค่าใช้จ่ายจากเหตุการณ์ย้อนหลัง</h2>
                <p class="mt-2 text-sm text-slate-700">
                    รวม {{ totals.count }} เหตุการณ์ · ความเสียหาย {{ baht(totals.damage) }} บาท · บ้านเรือนเสียหาย {{ totals.houses }} หลัง ·
                    ค่าใช้จ่ายจากเหตุการณ์ (F2–F5) {{ baht(totals.spend) }} บาท
                </p>
                <table v-if="lossByYear.length" class="mt-2 w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr class="border-b border-slate-100">
                            <th class="py-1.5">ปีงบ</th>
                            <th class="py-1.5 text-right">เหตุการณ์</th>
                            <th class="py-1.5 text-right">บ้านเสียหาย</th>
                            <th class="py-1.5 text-right">บาดเจ็บ/เสียชีวิต</th>
                            <th class="py-1.5 text-right">ความเสียหาย (บาท)</th>
                            <th class="py-1.5 text-right">ตอบสนอง+ฟื้นฟู</th>
                            <th class="py-1.5 text-right">ลดความเสี่ยง (F4)</th>
                            <th class="py-1.5 text-right">เยียวยา</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="y in lossByYear" :key="y.year" class="border-b border-slate-50">
                            <td class="py-1.5">{{ y.year }}</td>
                            <td class="py-1.5 text-right">{{ y.count }}</td>
                            <td class="py-1.5 text-right">{{ y.houses }}</td>
                            <td class="py-1.5 text-right">{{ y.injury }}/{{ y.death }}</td>
                            <td class="py-1.5 text-right">{{ baht(y.damage) }}</td>
                            <td class="py-1.5 text-right">{{ baht(y.f2 + y.f3) }}</td>
                            <td class="py-1.5 text-right">{{ baht(y.f4) }}</td>
                            <td class="py-1.5 text-right">{{ baht(y.f5) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-2 text-sm text-slate-400">ไม่มีเหตุการณ์ที่บันทึกในระบบ</p>
                <ul v-if="events.length" class="mt-3 space-y-1 text-xs text-slate-600">
                    <li v-for="e in events.slice(0, 8)" :key="e.id">
                        <b>{{ formatThaiDate(e.start) }}</b> {{ hazardLabel[e.hazard] }} ({{ e.id }}): ความเสียหาย {{ baht(e.damage) }} บาท ·
                        {{ e.description }}
                    </li>
                </ul>
            </section>

            <!-- 4. Budget + recovery -->
            <section :class="section">
                <h2 class="border-b border-slate-200 pb-1 font-semibold text-slate-800">4. งบประมาณด้านภัยและความพร้อมในการฟื้นฟู</h2>
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="text-sm text-slate-700">
                        <p>
                            งบด้านภัยเฉลี่ยย้อนหลัง 3 ปี <b>{{ baht(community.budget) }}</b> บาท
                        </p>
                        <p>
                            คิดเป็น <b>{{ baht(community.budgetPerPerson) }}</b> บาทต่อประชากร (ค่ากลางของเมือง
                            {{ baht(city.budgetPerPersonMedian) }} บาท/คน)
                        </p>
                        <p v-if="budgetRatio !== null" class="mt-1 font-medium" :class="budgetRatio < 0.8 ? 'text-red-600' : 'text-slate-600'">
                            {{
                                budgetRatio < 1
                                    ? `ต่ำกว่าค่ากลางของเมือง ${Math.round((1 - budgetRatio) * 100)}%`
                                    : `สูงกว่าค่ากลางของเมือง ${Math.round((budgetRatio - 1) * 100)}%`
                            }}
                        </p>
                        <p class="mt-2">
                            ความพร้อมฟื้นฟูเฉลี่ย (R1–R5) <b>{{ one(community.recovery) }}</b> จาก 5
                        </p>
                    </div>
                    <RadarChart
                        :axes="[...R_AXES]"
                        :series="[
                            { label: community.name, color: '#7c3aed', values: R_AXES.map((a) => community.recoveryItems[a.key]) },
                            { label: 'ค่าเฉลี่ยเมือง', color: '#94a3b8', values: R_AXES.map((a) => city.recoveryItems[a.key]), dashed: true },
                        ]"
                    />
                </div>
            </section>

            <!-- 5. Flags -->
            <section :class="section">
                <h2 class="border-b border-slate-200 pb-1 font-semibold text-slate-800">5. สัญญาณเตือนตามเกณฑ์ของเทศบาล (Policy Flag)</h2>
                <ul v-if="flags.length" class="mt-2 space-y-1.5 text-sm">
                    <li v-for="(f, i) in flags" :key="i" class="flex gap-2">
                        <i :class="[f.icon, 'mt-1 text-red-500']"></i
                        ><span
                            ><b>{{ f.label }}</b> — {{ f.reason }}</span
                        >
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">ไม่เข้าเงื่อนไขสัญญาณเตือนใด</p>
            </section>

            <!-- 6. MCR -->
            <section :class="section">
                <h2 class="border-b border-slate-200 pb-1 font-semibold text-slate-800">
                    6. หลักฐานตามกรอบ MCR2030 (ครอบคลุม {{ mcr.coverage.toFixed(1) }} จาก 10 หลัก)
                </h2>
                <ul class="mt-2 space-y-1 text-xs text-slate-600">
                    <li v-for="(e, key) in mcr.essentials" :key="key">
                        <b>{{ key }} {{ essentialLabels[key] }}</b>
                        <span :class="e.status === 'full' ? 'text-emerald-600' : e.status === 'partial' ? 'text-amber-600' : 'text-slate-400'">
                            ({{ e.status === 'full' ? 'มีข้อมูล' : e.status === 'partial' ? 'มีบางส่วน' : 'ยังไม่พบ' }})</span
                        >: {{ e.sentence }}
                    </li>
                </ul>
            </section>

            <p class="mt-8 border-t border-slate-200 pt-2 text-[11px] text-slate-400">
                คะแนนความเสี่ยงคำนวณตามสมการ HEVC ของระบบ (0–100) ระดับ: 0–24 ต่ำ, 25–49 ปานกลาง, 50–74 ค่อนข้างสูง, 75–100 สูง ·
                ตัวเลขทุกตัวมาจากข้อมูลที่หน่วยงานนำเข้าระบบ
            </p>
        </div>
    </AdminLayout>
</template>
