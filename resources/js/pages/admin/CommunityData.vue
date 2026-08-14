<script setup lang="ts">
import DonutChart from '@/components/admin/DonutChart.vue';
import { seededValue } from '@/data/hazardMeta';
import { communities, type Community } from '@/data/mahasarakham';
import { calculateRecoveryScore, defaultCriteria } from '@/data/mockScoringCriteria';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

// Fully mock — there's no communities/hazard_assessments table yet (see CLAUDE.md). Replaces the old
// admin-only "จัดการชุมชน" CRUD page: this is a read-oriented registry/profile view instead, covering the
// full ภัยพิบัติรวม "SHARED-scope input" field set (Meta + Vulnerability groups + Capacity/Recovery shared
// baselines + Data Governance) rather than จัดการชุมชน's small 6-field starter subset — matches a `06.png`
// UI reference the project owner supplied, and moved from an admin-only settings submenu item to a flat
// admin+staff nav item right under นำเข้าข้อมูล per product decision. Every figure is derived
// deterministically from the real 32-community list in data/mahasarakham.ts via seededValue(), same pattern
// as ภัยพิบัติรวม/รายภัย. Field names mirror the real source workbook's columns 1:1 (confirmed against the
// xlsx: C_SOCIAL_SHARED_SCORE, C_PHYSICAL_SHARED_SCORE, C_OPERATIONAL_SHARED_SCORE, C_EVAC_NETWORK_SHARED_
// SCORE, R1_SHARED..R5_SHARED all exist verbatim in every per-hazard sheet as the "shared" baseline before
// a per-hazard override). R_SHARED_SCORE reuses the shared calculateRecoveryScore() engine against the
// default เกณฑ์คะแนน rather than being invented separately. Unlike จัดการชุมชน, there is no create/edit/
// delete modal here — the bottom action buttons are decorative, matching the mock-action-button pattern
// used elsewhere (ยืนยันนำเข้าข้อมูล, อัปเดตผลคำนวณ, ...).

interface CommunityDataRecord {
    id: number;
    code: string;
    name: string;
    tambon: string;
    amphoe: string;
    province: string;
    lat: number;
    lng: number;
    population: number;
    households: number;
    areaKm2: number;
    vElderlyCount: number;
    vChildCount: number;
    vBedriddenDisabledCount: number;
    vLowIncomeCount: number;
    cSocialScore: number;
    cPhysicalScore: number;
    cOperationalScore: number;
    cEvacNetworkScore: number;
    cTotalSharedScore: number;
    r1Shared: number;
    r2Shared: number;
    r3Shared: number;
    r4Shared: number;
    r5Shared: number;
    rSharedScore: number;
    source: string;
    evidence: string;
    dataDate: string;
    responsibleAgency: string;
    verificationStatus: 'ผ่านการตรวจสอบ' | 'กำลังตรวจสอบ';
    dataQuality: 'พร้อมใช้เป็นค่าร่วม' | 'คำเตือน';
}

function communityCode(id: number) {
    return `R${String(id).padStart(3, '0')}`;
}
function round1(n: number) {
    return Math.round(n * 10) / 10;
}
function round2(n: number) {
    return Math.round(n * 100) / 100;
}

const records = ref<CommunityDataRecord[]>(
    communities.map((c: Community) => {
        const population = Math.round(600 + seededValue(c.id) * 32);
        const households = Math.round(200 + seededValue(c.id + 0.5) * 10.5);
        const r1Shared = round1(2.5 + seededValue(c.id * 3 + 1) / 40);
        const r2Shared = round1(2.5 + seededValue(c.id * 3 + 2) / 40);
        const r3Shared = round1(2.5 + seededValue(c.id * 3 + 3) / 40);
        const r4Shared = round1(2.5 + seededValue(c.id * 3 + 4) / 40);
        const r5Shared = round1(2.5 + seededValue(c.id * 3 + 5) / 40);
        const cSocialScore = round1(3 + seededValue(c.id + 0.11) / 50);
        const cPhysicalScore = round1(3 + seededValue(c.id + 0.22) / 50);
        const cOperationalScore = round1(3 + seededValue(c.id + 0.33) / 50);
        const cEvacNetworkScore = round1(3 + seededValue(c.id + 0.44) / 50);

        return {
            id: c.id,
            code: communityCode(c.id),
            name: c.name,
            tambon: 'ตลาด',
            amphoe: 'เมืองมหาสารคาม',
            province: 'มหาสารคาม',
            lat: c.lat,
            lng: c.lng,
            population,
            households,
            areaKm2: Math.round((0.3 + seededValue(c.id + 0.25) * 2.2) * 100) / 100,
            // Shares of population — seededValue() is 0-100, so each /divisor caps how much the baseline
            // share can drift (e.g. elderly 13%-18%, matching the ~16% shown in the real ภัยพิบัติรวม sample
            // row cross-checked against the source workbook).
            vElderlyCount: Math.round(population * (0.13 + seededValue(c.id + 0.6) / 2000)),
            vChildCount: Math.round(population * (0.09 + seededValue(c.id + 0.7) / 2500)),
            vBedriddenDisabledCount: Math.round(population * (0.005 + seededValue(c.id + 0.8) / 10000)),
            vLowIncomeCount: Math.round(population * (0.08 + seededValue(c.id + 0.9) / 2000)),
            cSocialScore,
            cPhysicalScore,
            cOperationalScore,
            cEvacNetworkScore,
            cTotalSharedScore: round2((cSocialScore + cPhysicalScore + cOperationalScore + cEvacNetworkScore) / 4),
            r1Shared,
            r2Shared,
            r3Shared,
            r4Shared,
            r5Shared,
            rSharedScore: calculateRecoveryScore(defaultCriteria().weights, {
                r1: r1Shared,
                r2: r2Shared,
                r3: r3Shared,
                r4: r4Shared,
                r5: r5Shared,
            }),
            source: 'ข้อมูลจำลองเพื่อทดสอบระบบ HEVC',
            evidence: `SIM-CM-${communityCode(c.id)}`,
            dataDate: '03/07/2569',
            responsibleAgency: 'เทศบาลเมืองมหาสารคาม',
            verificationStatus: seededValue(c.id + 0.95) >= 20 ? 'ผ่านการตรวจสอบ' : 'กำลังตรวจสอบ',
            dataQuality: seededValue(c.id + 0.85) >= 15 ? 'พร้อมใช้เป็นค่าร่วม' : 'คำเตือน',
        };
    }),
);

const dataQualityBadgeClass: Record<string, string> = {
    พร้อมใช้เป็นค่าร่วม: 'bg-emerald-50 text-emerald-600',
    คำเตือน: 'bg-amber-50 text-amber-600',
};

// ── Stat row (totals across all 32 communities) ──
const totalPopulation = computed(() => records.value.reduce((s, r) => s + r.population, 0));
const totalHouseholds = computed(() => records.value.reduce((s, r) => s + r.households, 0));
const totalElderly = computed(() => records.value.reduce((s, r) => s + r.vElderlyCount, 0));
const totalChild = computed(() => records.value.reduce((s, r) => s + r.vChildCount, 0));
const totalBedriddenDisabled = computed(() => records.value.reduce((s, r) => s + r.vBedriddenDisabledCount, 0));
const totalLowIncome = computed(() => records.value.reduce((s, r) => s + r.vLowIncomeCount, 0));

// ── Filters ──
const tambonOptions = computed(() => Array.from(new Set(records.value.map((r) => r.tambon))));
const filterTambon = ref<'all' | string>('all');
const filterDataQuality = ref<'all' | CommunityDataRecord['dataQuality']>('all');
const searchQuery = ref('');

const filteredRecords = computed(() =>
    records.value
        .filter((r) => filterTambon.value === 'all' || r.tambon === filterTambon.value)
        .filter((r) => filterDataQuality.value === 'all' || r.dataQuality === filterDataQuality.value)
        .filter((r) => {
            const q = searchQuery.value.trim().toLowerCase();
            if (!q) return true;
            return r.name.toLowerCase().includes(q) || r.code.toLowerCase().includes(q);
        }),
);

// ── Pagination — 10 rows/page ──
const currentPage = ref(1);
const pageSize = 10;
const totalPages = computed(() => Math.max(1, Math.ceil(filteredRecords.value.length / pageSize)));
const pagedRecords = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    return filteredRecords.value.slice(start, start + pageSize);
});
function goToPage(page: number) {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
}

// ── โปรไฟล์ชุมชน — selected row ──
const selectedId = ref<number>(records.value[0]?.id ?? 0);
const selectedCommunity = computed(() => records.value.find((r) => r.id === selectedId.value) ?? records.value[0]);
function selectCommunity(record: CommunityDataRecord) {
    selectedId.value = record.id;
}

// ── กลุ่มเปราะบางของชุมชน — donut over selected community's population, with a residual "other" segment
// so the legend percentages read as a genuine share of that community's population. ──
const vulnerableSegments = computed(() => {
    const sc = selectedCommunity.value;
    const named = sc.vElderlyCount + sc.vChildCount + sc.vBedriddenDisabledCount + sc.vLowIncomeCount;
    return [
        { label: 'ผู้สูงอายุ', value: sc.vElderlyCount, color: '#2563eb' },
        { label: 'เด็ก', value: sc.vChildCount, color: '#16a34a' },
        { label: 'ติดเตียง/พิการ', value: sc.vBedriddenDisabledCount, color: '#dc2626' },
        { label: 'รายได้น้อย', value: sc.vLowIncomeCount, color: '#f59e0b' },
        { label: 'ประชากรกลุ่มอื่น', value: Math.max(sc.population - named, 0), color: '#e2e8f0' },
    ];
});

// ── สรุป Capacity และ Recovery ──
const capacityRows = computed(() => {
    const sc = selectedCommunity.value;
    return [
        { key: 'C_SOCIAL_SCORE', value: sc.cSocialScore },
        { key: 'C_PHYSICAL_SCORE', value: sc.cPhysicalScore },
        { key: 'C_OPERATIONAL_SCORE', value: sc.cOperationalScore },
        { key: 'C_EVAC_NETWORK_SCORE', value: sc.cEvacNetworkScore },
        { key: 'C_TOTAL_SHARED_SCORE', value: sc.cTotalSharedScore, emphasized: true },
    ];
});
const recoveryRows = computed(() => {
    const sc = selectedCommunity.value;
    return [
        { key: 'R1_SHARED', value: sc.r1Shared },
        { key: 'R2_SHARED', value: sc.r2Shared },
        { key: 'R3_SHARED', value: sc.r3Shared },
        { key: 'R4_SHARED', value: sc.r4Shared },
        { key: 'R5_SHARED', value: sc.r5Shared },
        { key: 'R_SHARED_SCORE', value: sc.rSharedScore, emphasized: true },
    ];
});

// ── โครงสร้างข้อมูลในเมนูข้อมูลชุมชน — field-dictionary reference, same pattern as EventsLD's
// "กรอบข้อมูล L&D และ Recovery" bottom row. ──
const fieldGroups = [
    {
        key: 'basic',
        label: 'ข้อมูลพื้นฐาน',
        icon: 'fa-solid fa-id-card',
        color: '#2563eb',
        fields: ['ASSESSMENT_DATE', 'COMMUNITY_ID', 'COMMUNITY_NAME', 'TAMBON', 'AMPHOE', 'PROVINCE'],
    },
    {
        key: 'population',
        label: 'ประชากรและครัวเรือน',
        icon: 'fa-solid fa-people-roof',
        color: '#16a34a',
        fields: ['POPULATION', 'HOUSEHOLDS', 'AREA_KM2'],
    },
    {
        key: 'vulnerable',
        label: 'กลุ่มเปราะบาง',
        icon: 'fa-solid fa-hand-holding-heart',
        color: '#dc2626',
        fields: ['V_ELDERLY_COUNT', 'V_CHILD_COUNT', 'V_BEDRIDDEN_DISABLED_COUNT', 'V_LOW_INCOME_COUNT'],
    },
    {
        key: 'capacityRecovery',
        label: 'Capacity / Recovery',
        icon: 'fa-solid fa-shield-heart',
        color: '#0ea5e9',
        fields: ['C1 ถึง C8', 'C_TOTAL_SHARED_SCORE', 'R1_SHARED ถึง R5_SHARED', 'R_SHARED_SCORE'],
    },
    {
        key: 'governance',
        label: 'Data Governance',
        icon: 'fa-solid fa-landmark',
        color: '#8b5cf6',
        fields: ['SOURCE', 'EVIDENCE', 'DATA_DATE', 'RESPONSIBLE_AGENCY', 'VERIFICATION_STATUS', 'DATA_QUALITY'],
    },
];

// ── Mini locator map — module-scope Leaflet instance, non-interactive (it's a thumbnail, not a navigable
// map), recentered on whichever community is selected. Same map-pages pattern as elsewhere: plain `let`,
// not ref/reactive, and map.remove() in onUnmounted since Inertia navigation unmounts without a reload. ──
let profileMap: L.Map | null = null;
let profileMarker: L.Marker | null = null;
const mapEl = ref<HTMLDivElement | null>(null);

function initProfileMap() {
    if (!mapEl.value || profileMap) return;
    const sc = selectedCommunity.value;
    profileMap = L.map(mapEl.value, {
        zoomControl: false,
        dragging: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        touchZoom: false,
        boxZoom: false,
        keyboard: false,
    }).setView([sc.lat, sc.lng], 15);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap',
        subdomains: 'abcd',
        maxZoom: 20,
    }).addTo(profileMap);

    profileMarker = L.marker([sc.lat, sc.lng]).addTo(profileMap);
}

watch(selectedCommunity, (sc) => {
    if (!profileMap || !profileMarker) return;
    profileMap.setView([sc.lat, sc.lng], 15);
    profileMarker.setLatLng([sc.lat, sc.lng]);
});

onMounted(() => nextTick(initProfileMap));
onUnmounted(() => {
    profileMap?.remove();
    profileMap = null;
});
</script>

<template>
    <Head title="ข้อมูลชุมชน" />

    <AdminLayout title="ข้อมูลชุมชน">
        <div class="mb-6">
            <h1 class="text-lg font-bold text-slate-800">ข้อมูลชุมชน</h1>
            <p class="mt-1 text-sm text-slate-500">
                จัดการข้อมูลพื้นฐาน ประชากร กลุ่มเปราะบาง Capacity ข้อมูลงบประมาณ และสถานะคุณภาพข้อมูลระดับชุมชน
            </p>
        </div>

        <!-- Stat row -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-xs text-blue-600">
                        <i class="fa-solid fa-users"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">จำนวนชุมชน</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ records.length }}</span
                    ><span class="text-[10px] text-slate-400">ชุมชน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-xs text-indigo-600">
                        <i class="fa-solid fa-people-group"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ประชากรรวม</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalPopulation.toLocaleString('th-TH') }}</span
                    ><span class="text-[10px] text-slate-400">คน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-xs text-teal-600">
                        <i class="fa-solid fa-house"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ครัวเรือนรวม</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalHouseholds.toLocaleString('th-TH') }}</span
                    ><span class="text-[10px] text-slate-400">ครัวเรือน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-xs text-violet-600">
                        <i class="fa-solid fa-person-cane"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ผู้สูงอายุ</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalElderly.toLocaleString('th-TH') }}</span
                    ><span class="text-[10px] text-slate-400">คน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-xs text-emerald-600">
                        <i class="fa-solid fa-child"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">เด็ก</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalChild.toLocaleString('th-TH') }}</span
                    ><span class="text-[10px] text-slate-400">คน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-50 text-xs text-red-600">
                        <i class="fa-solid fa-wheelchair"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ติดเตียง/พิการ</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalBedriddenDisabled.toLocaleString('th-TH') }}</span
                    ><span class="text-[10px] text-slate-400">คน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-xs text-orange-600">
                        <i class="fa-solid fa-sack-dollar"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">รายได้น้อย</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalLowIncome.toLocaleString('th-TH') }}</span
                    ><span class="text-[10px] text-slate-400">คน</span>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- ทะเบียนชุมชน -->
            <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                    <div>
                        <h2 class="font-semibold text-slate-800">ทะเบียนชุมชน</h2>
                        <p class="mt-0.5 text-xs text-slate-400">คลิกแถวเพื่อดูโปรไฟล์ชุมชน</p>
                    </div>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-xl border border-slate-200 px-3.5 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                    >
                        <i class="fa-solid fa-download"></i>
                        ส่งออก
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50/50 px-5 py-4">
                    <select
                        v-model="filterTambon"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none"
                    >
                        <option value="all">ตำบล: ทั้งหมด</option>
                        <option v-for="t in tambonOptions" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <select
                        v-model="filterDataQuality"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none"
                    >
                        <option value="all">สถานะข้อมูล: ทั้งหมด</option>
                        <option value="พร้อมใช้เป็นค่าร่วม">พร้อมใช้เป็นค่าร่วม</option>
                        <option value="คำเตือน">คำเตือน</option>
                    </select>
                    <div class="relative min-w-[180px] flex-1">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-300"></i>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="ค้นหาชุมชน..."
                            class="w-full rounded-xl border border-slate-200 bg-white py-2 pl-8 pr-3 text-sm text-slate-700 focus:border-blue-500 focus:outline-none"
                        />
                    </div>
                    <button
                        type="button"
                        class="flex shrink-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                    >
                        <i class="fa-solid fa-sliders"></i>
                        ตัวกรองเพิ่มเติม
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2.5 font-medium">COMMUNITY_ID</th>
                                <th class="whitespace-nowrap px-3 py-2.5 font-medium">COMMUNITY_NAME</th>
                                <th class="whitespace-nowrap px-3 py-2.5 font-medium">TAMBON</th>
                                <th class="whitespace-nowrap px-3 py-2.5 text-right font-medium">POPULATION</th>
                                <th class="whitespace-nowrap px-3 py-2.5 text-right font-medium">HOUSEHOLDS</th>
                                <th class="whitespace-nowrap px-3 py-2.5 text-right font-medium">AREA_KM2</th>
                                <th class="whitespace-nowrap px-3 py-2.5 text-right font-medium">C_TOTAL_SHARED_SCORE</th>
                                <th class="whitespace-nowrap px-3 py-2.5 text-right font-medium">R_SHARED_SCORE</th>
                                <th class="whitespace-nowrap px-3 py-2.5 font-medium">DATA_QUALITY</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="record in pagedRecords"
                                :key="record.id"
                                class="cursor-pointer"
                                :class="record.id === selectedId ? 'bg-blue-50/70' : 'hover:bg-slate-50'"
                                @click="selectCommunity(record)"
                            >
                                <td
                                    class="whitespace-nowrap px-3 py-2.5 font-medium"
                                    :class="record.id === selectedId ? 'text-blue-600' : 'text-slate-700'"
                                >
                                    {{ record.code }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5" :class="record.id === selectedId ? 'text-blue-600' : 'text-slate-700'">
                                    {{ record.name }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-slate-500">{{ record.tambon }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right text-slate-500">
                                    {{ record.population.toLocaleString('th-TH') }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right text-slate-500">
                                    {{ record.households.toLocaleString('th-TH') }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right text-slate-500">{{ record.areaKm2 }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium text-slate-700">{{ record.cTotalSharedScore }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium text-slate-700">{{ record.rSharedScore }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="dataQualityBadgeClass[record.dataQuality]"
                                    >
                                        {{ record.dataQuality }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="pagedRecords.length === 0">
                                <td colspan="9" class="px-3 py-10 text-center text-sm text-slate-400">ไม่พบชุมชนตามเงื่อนไขที่เลือก</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="filteredRecords.length > 0"
                    class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500"
                >
                    <span>
                        แสดง {{ (currentPage - 1) * pageSize + 1 }}-{{ Math.min(currentPage * pageSize, filteredRecords.length) }} จาก
                        {{ filteredRecords.length }} รายการ
                    </span>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            @click="goToPage(currentPage - 1)"
                            :disabled="currentPage === 1"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <i class="fa-solid fa-chevron-left text-xs"></i>
                        </button>
                        <button
                            v-for="page in totalPages"
                            :key="page"
                            type="button"
                            @click="goToPage(page)"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-sm"
                            :class="page === currentPage ? 'bg-blue-600 font-medium text-white' : 'text-slate-500 hover:bg-slate-100'"
                        >
                            {{ page }}
                        </button>
                        <button
                            type="button"
                            @click="goToPage(currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <i class="fa-solid fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right column: โปรไฟล์ชุมชน / กลุ่มเปราะบาง / Capacity+Recovery -->
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold text-slate-800">โปรไฟล์ชุมชน</h2>
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-600">{{ selectedCommunity.code }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 text-xs">
                        <div>
                            <dt class="text-slate-400">COMMUNITY_ID</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.code }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">ประชากร</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.population.toLocaleString('th-TH') }} คน</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">ชื่อชุมชน</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">ครัวเรือน</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.households.toLocaleString('th-TH') }} ครัวเรือน</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">ตำบล</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.tambon }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">พื้นที่</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.areaKm2 }} ตร.กม.</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">อำเภอ</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.amphoe }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">หน่วยงานรับผิดชอบ</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.responsibleAgency }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">จังหวัด</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.province }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">DATA_DATE</dt>
                            <dd class="font-medium text-slate-700">{{ selectedCommunity.dataDate }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-600">
                            <i class="fa-solid fa-circle-check mr-1"></i>{{ selectedCommunity.verificationStatus }}
                        </span>
                        <span
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                            :class="dataQualityBadgeClass[selectedCommunity.dataQuality]"
                        >
                            {{ selectedCommunity.dataQuality }}
                        </span>
                    </div>

                    <div ref="mapEl" class="mt-3 h-32 w-full rounded-xl bg-slate-50"></div>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-1 font-semibold text-slate-800">กลุ่มเปราะบางของชุมชน</h2>
                    <p class="mb-3 text-xs text-slate-400">{{ selectedCommunity.name }}</p>
                    <DonutChart :segments="vulnerableSegments" center-label="คน" />
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">สรุป Capacity และ Recovery</h2>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="space-y-2.5">
                            <div v-for="row in capacityRows" :key="row.key" class="flex items-center gap-2 text-xs">
                                <span
                                    class="w-[150px] shrink-0 truncate"
                                    :class="row.emphasized ? 'font-semibold text-slate-600' : 'text-slate-400'"
                                    >{{ row.key }}</span
                                >
                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                    <span
                                        class="block h-full rounded-full"
                                        :class="row.emphasized ? 'bg-blue-600' : 'bg-sky-400'"
                                        :style="{ width: (row.value / 5) * 100 + '%' }"
                                    ></span>
                                </span>
                                <span class="w-8 shrink-0 text-right font-semibold text-slate-700">{{ row.value }}</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div
                                v-for="row in recoveryRows"
                                :key="row.key"
                                class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-xs"
                                :class="row.emphasized ? 'bg-emerald-50' : ''"
                            >
                                <span class="flex items-center gap-1.5" :class="row.emphasized ? 'font-semibold text-emerald-700' : 'text-slate-500'">
                                    <i class="fa-solid fa-circle-check text-[10px] text-emerald-500"></i>
                                    {{ row.key }}
                                </span>
                                <span class="font-semibold" :class="row.emphasized ? 'text-emerald-700' : 'text-slate-700'">{{ row.value }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- โครงสร้างข้อมูลในเมนูข้อมูลชุมชน -->
        <div class="mt-6 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold text-slate-800">โครงสร้างข้อมูลในเมนูข้อมูลชุมชน</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <div v-for="g in fieldGroups" :key="g.key" class="rounded-xl border border-slate-100 p-4">
                    <span
                        class="mb-2 flex h-9 w-9 items-center justify-center rounded-lg"
                        :style="{ backgroundColor: g.color + '1a', color: g.color }"
                    >
                        <i :class="g.icon"></i>
                    </span>
                    <p class="text-sm font-semibold text-slate-800">{{ g.label }}</p>
                    <ul class="mt-2 space-y-1 text-[11px] text-slate-500">
                        <li v-for="f in g.fields" :key="f">{{ f }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Action bar -->
        <div class="mt-6 flex flex-col items-stretch justify-end gap-3 sm:flex-row">
            <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fa-regular fa-floppy-disk mr-1.5"></i>
                บันทึกฉบับร่าง
            </button>
            <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fa-solid fa-download mr-1.5"></i>
                ดาวน์โหลดข้อมูล
            </button>
            <button type="button" class="rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                <i class="fa-solid fa-check mr-1.5"></i>
                บันทึกข้อมูลชุมชน
            </button>
        </div>
    </AdminLayout>
</template>
