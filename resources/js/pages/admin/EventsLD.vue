<script setup lang="ts">
import BarChart from '@/components/admin/BarChart.vue';
import DonutChart from '@/components/admin/DonutChart.vue';
import { communityZones } from '@/data/communityZones';
import { hazardBadgeClass, hazardIcons, hazardOrder, hazardShortLabel, riskLevelMeta, type RiskLevel } from '@/data/hazardMeta';
import { MAP_CENTER, MAP_ZOOM, MUNICIPALITY_BOUNDARY, communities, riskColors, type Community } from '@/data/mahasarakham';
import { communityName, eventRecoveryScore, events, isMultiHazard, nextEventId, type EventRecord, type VerificationStatus } from '@/data/mockEvents';
import { calculateRecoveryScore, defaultCriteria } from '@/data/mockScoringCriteria';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, nextTick, onUnmounted, reactive, ref } from 'vue';

// Mock CRUD — matches the เหตุการณ์_LD sheet: 1 row per event, 25 independently-entered fields.
// `events` is the shared module-level ref (data/mockEvents.ts) that Dashboard.vue's "เหตุการณ์ล่าสุด"
// preview and รายภัย's L&D card also read, so entries added here show up there too within the same session.
// Redesigned per a `05.png` UI reference the project owner supplied — stat row, hazard-toggle filter chips,
// a wider registry table, a สถานะข้อมูล/แจกแจงประเภทภัย/Recovery sidebar, and a field-dictionary reference
// row, following the same shape as the ภัยพิบัติรวม and รายภัย redesigns earlier.
//
// 3 fields are derived, never stored: COMMUNITY_NAME (looked up from COMMUNITY_ID), MULTI_HAZARD_FLAG
// (derived from RELATED_HAZARDS being non-empty), R_OBSERVED_SCORE (computed live from R1-R5 using the
// default เกณฑ์คะแนน's Recovery weights — verified against the real EV0001 sample in the source workbook).
//
// "พร้อมรวมผล" vs "ข้อมูลไม่ครบ" (data-completeness, used for the stat row / donut / bar chart) reuses the
// event's own real DATA_QUALITY field ('พร้อมใช้เป็นค่าร่วม' = ready) rather than inventing a parallel flag.

const verificationOptions: VerificationStatus[] = ['ยืนยันแล้ว', 'กำลังตรวจสอบ'];

function round2(n: number) {
    return Math.round(n * 100) / 100;
}

function isEventComplete(e: EventRecord) {
    return e.dataQuality === 'พร้อมใช้เป็นค่าร่วม';
}

const dataQualityBadgeClass: Record<string, string> = {
    พร้อมใช้เป็นค่าร่วม: 'bg-emerald-50 text-emerald-600',
    คำเตือน: 'bg-amber-50 text-amber-600',
};

// Recovery "goodness" banding — deliberately not riskLevelMeta: a high RECOVERY score is a *good* outcome,
// so reusing the risk ต่ำ/ปานกลาง/สูง/สูงมาก palette (where "สูง" means dangerous) would read backwards.
const recoveryBandMeta = [
    { min: 4, label: 'ฟื้นตัวดี', color: '#16a34a' },
    { min: 2.5, label: 'ปานกลาง', color: '#d97706' },
    { min: 0, label: 'ต้องติดตาม', color: '#dc2626' },
];
function recoveryBand(score: number) {
    return recoveryBandMeta.find((b) => score >= b.min) ?? recoveryBandMeta[recoveryBandMeta.length - 1];
}

// ── Filters ──
const hazardEnabled = reactive<Record<Community['activeRisk'], boolean>>(
    Object.fromEntries(hazardOrder.map((h) => [h, true])) as Record<Community['activeRisk'], boolean>,
);
function toggleHazardFilter(h: Community['activeRisk']) {
    hazardEnabled[h] = !hazardEnabled[h];
}

const filterVerification = ref<'all' | VerificationStatus>('all');
const filterStartDate = ref('2025-01-01');
const filterEndDate = ref('2026-12-31');
const searchQuery = ref('');

const filteredEvents = computed(() =>
    [...events.value]
        .filter((e) => hazardEnabled[e.primaryHazard])
        .filter((e) => filterVerification.value === 'all' || e.verificationStatus === filterVerification.value)
        .filter((e) => e.eventStart >= filterStartDate.value && e.eventStart <= filterEndDate.value)
        .filter((e) => {
            const q = searchQuery.value.trim().toLowerCase();
            if (!q) return true;
            return e.id.toLowerCase().includes(q) || communityName(e.communityId).toLowerCase().includes(q);
        })
        .sort((a, b) => b.eventStart.localeCompare(a.eventStart)),
);

// ── Pagination — 10 rows/page ──
const currentPage = ref(1);
const pageSize = 10;
const totalPages = computed(() => Math.max(1, Math.ceil(filteredEvents.value.length / pageSize)));
const pagedEvents = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    return filteredEvents.value.slice(start, start + pageSize);
});
function goToPage(page: number) {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
}

// ── Stat row ──
const completeEvents = computed(() => events.value.filter(isEventComplete));
const incompleteEvents = computed(() => events.value.filter((e) => !isEventComplete(e)));
const totalDamageThb = computed(() => events.value.reduce((s, e) => s + e.l3DamageValueThb, 0));
const totalHousesDamaged = computed(() => events.value.reduce((s, e) => s + e.l1HousesDamaged, 0));
const totalInjuries = computed(() => events.value.reduce((s, e) => s + e.l2Injuries, 0));
const totalDeaths = computed(() => events.value.reduce((s, e) => s + e.l2Deaths, 0));
const avgRecoveryDays = computed(() =>
    events.value.length ? Math.round(events.value.reduce((s, e) => s + e.l4RecoveryDays, 0) / events.value.length) : 0,
);
const avgRecoveryScore = computed(() =>
    events.value.length ? round2(events.value.reduce((s, e) => s + eventRecoveryScore(e), 0) / events.value.length) : 0,
);

const avgR = computed(() => {
    const n = events.value.length || 1;
    return {
        r1: round2(events.value.reduce((s, e) => s + e.r1Observed, 0) / n),
        r2: round2(events.value.reduce((s, e) => s + e.r2Observed, 0) / n),
        r3: round2(events.value.reduce((s, e) => s + e.r3Observed, 0) / n),
        r4: round2(events.value.reduce((s, e) => s + e.r4Observed, 0) / n),
        r5: round2(events.value.reduce((s, e) => s + e.r5Observed, 0) / n),
    };
});

// R1-R5 plus the aggregate R_OBSERVED_SCORE, rendered together as one bar list in the Recovery card.
const recoveryRows = computed(() => [
    { key: 'R1_OBSERVED', value: avgR.value.r1 },
    { key: 'R2_OBSERVED', value: avgR.value.r2 },
    { key: 'R3_OBSERVED', value: avgR.value.r3 },
    { key: 'R4_OBSERVED', value: avgR.value.r4 },
    { key: 'R5_OBSERVED', value: avgR.value.r5 },
    { key: 'R_OBSERVED_SCORE', value: avgRecoveryScore.value, emphasized: true },
]);

const GAUGE_RADIUS = 34;
const GAUGE_CIRCUMFERENCE = 2 * Math.PI * GAUGE_RADIUS;

const dataStatusSegments = computed(() => [
    { label: 'พร้อมรวมผล', value: completeEvents.value.length, color: '#16a34a' },
    { label: 'ข้อมูลเหตุ/หลักฐานไม่ครบ', value: incompleteEvents.value.length, color: '#94a3b8' },
]);

const hazardCompleteBars = computed(() =>
    hazardOrder.map((h) => ({
        label: hazardShortLabel[h],
        value: completeEvents.value.filter((e) => e.primaryHazard === h).length,
        color: riskColors[h],
    })),
);

// ── Modal state ──
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingId = ref<string | null>(null);

function blankForm(): EventRecord {
    return {
        id: '',
        communityId: communities[0].id,
        eventStart: '',
        eventEnd: '',
        primaryHazard: 'flood',
        relatedHazards: [],
        description: '',
        l1HousesDamaged: 0,
        l2Injuries: 0,
        l2Deaths: 0,
        l3DamageValueThb: 0,
        l4RecoveryDays: 0,
        l5RepeatDamage: false,
        r1Observed: 3,
        r2Observed: 3,
        r3Observed: 3,
        r4Observed: 3,
        r5Observed: 3,
        source: '',
        evidence: '',
        dataDate: '',
        responsibleAgency: '',
        verificationStatus: 'กำลังตรวจสอบ',
        dataQuality: '',
        notes: '',
    };
}

const form = ref<EventRecord>(blankForm());

function openCreateModal() {
    modalMode.value = 'create';
    editingId.value = null;
    form.value = blankForm();
    modalOpen.value = true;
}

function openEditModal(event: EventRecord) {
    modalMode.value = 'edit';
    editingId.value = event.id;
    form.value = { ...event, relatedHazards: [...event.relatedHazards] };
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function toggleRelatedHazard(hazard: Community['activeRisk']) {
    const i = form.value.relatedHazards.indexOf(hazard);
    if (i === -1) form.value.relatedHazards.push(hazard);
    else form.value.relatedHazards.splice(i, 1);
}

function submit() {
    if (modalMode.value === 'create') {
        events.value.push({ ...form.value, id: nextEventId() });
    } else if (editingId.value !== null) {
        const target = events.value.find((e) => e.id === editingId.value);
        if (target) Object.assign(target, form.value, { id: target.id });
    }
    closeModal();
}

function destroyEvent(event: EventRecord) {
    if (!confirm(`ยืนยันลบเหตุการณ์ "${event.id}" ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`)) return;
    events.value = events.value.filter((e) => e.id !== event.id);
    goToPage(currentPage.value);
}

// ── Live-derived fields shown in the form ──
const formCommunityName = computed(() => communityName(form.value.communityId));
const formMultiHazardFlag = computed(() => form.value.relatedHazards.length > 0);
const formRecoveryScore = computed(() =>
    calculateRecoveryScore(defaultCriteria().weights, {
        r1: form.value.r1Observed,
        r2: form.value.r2Observed,
        r3: form.value.r3Observed,
        r4: form.value.r4Observed,
        r5: form.value.r5Observed,
    }),
);

const inputClass =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none';
const labelClass = 'mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase';

// ── โครงสร้างข้อมูล reference groups — the sheet's real column groups ──
const fieldGroups = [
    {
        key: 'event',
        label: 'ข้อมูลเหตุการณ์',
        icon: 'fa-solid fa-file-lines',
        color: '#2563eb',
        fields: ['EVENT_ID', 'COMMUNITY_ID', 'COMMUNITY_NAME', 'EVENT_START', 'EVENT_END'],
    },
    {
        key: 'hazardType',
        label: 'ประเภทภัย',
        icon: 'fa-solid fa-triangle-exclamation',
        color: '#f97316',
        fields: ['PRIMARY_HAZARD', 'RELATED_HAZARDS', 'MULTI_HAZARD_FLAG'],
    },
    {
        key: 'lossDamage',
        label: 'Loss & Damage',
        icon: 'fa-solid fa-house-chimney-crack',
        color: '#dc2626',
        fields: ['L1_HOUSES_DAMAGED', 'L2_INJURIES', 'L2_DEATHS', 'L3_DAMAGE_VALUE_THB', 'L4_RECOVERY_DAYS', 'L5_REPEAT_DAMAGE'],
    },
    {
        key: 'recovery',
        label: 'Recovery observed',
        icon: 'fa-solid fa-rotate',
        color: '#16a34a',
        fields: ['R1_OBSERVED ถึง R5_OBSERVED', 'และ R_OBSERVED_SCORE'],
    },
    {
        key: 'governance',
        label: 'Data Governance',
        icon: 'fa-solid fa-landmark',
        color: '#0ea5e9',
        fields: ['SOURCE', 'EVIDENCE', 'DATA_DATE', 'RESPONSIBLE_AGENCY'],
    },
    {
        key: 'quality',
        label: 'คุณภาพข้อมูล',
        icon: 'fa-solid fa-shield-halved',
        color: '#8b5cf6',
        fields: ['VERIFICATION_STATUS', 'DATA_QUALITY', 'NOTES'],
    },
];

// ── ตาราง ⇄ ผังพื้นที่ toggle — same pattern as DisasterOverview.vue/HazardAnalysis.vue's zone view
// (basemap TileLayer + Voronoi-clipped community zones), colored here by how many *filtered* events each
// community has — most communities will show "ไม่มีเหตุการณ์" since the mock register only seeds a
// handful of events, which is an honest reflection of the current (small) shared events store. ──
type EventDensityLevel = RiskLevel | 'none';
const densityMeta: Record<EventDensityLevel, { label: string; color: string }> = {
    ...riskLevelMeta,
    none: { label: 'ไม่มีเหตุการณ์', color: '#cbd5e1' },
};

const eventStatsByCommunity = computed(() => {
    const map = new Map<number, { count: number; damage: number }>();
    filteredEvents.value.forEach((e) => {
        const cur = map.get(e.communityId) ?? { count: 0, damage: 0 };
        cur.count += 1;
        cur.damage += e.l3DamageValueThb;
        map.set(e.communityId, cur);
    });
    return map;
});

function densityLevelForCount(count: number): EventDensityLevel {
    if (count === 0) return 'none';
    if (count === 1) return 'low';
    if (count <= 3) return 'medium';
    return 'high';
}

const tableViewMode = ref<'table' | 'map'>('table');
let zoneMap: L.Map | null = null;
const zoneMapEl = ref<HTMLDivElement | null>(null);

function initZoneMap() {
    if (!zoneMapEl.value || zoneMap) return;

    zoneMap = L.map(zoneMapEl.value, { scrollWheelZoom: false }).setView(MAP_CENTER, MAP_ZOOM);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap',
        subdomains: 'abcd',
        maxZoom: 20,
    }).addTo(zoneMap);

    L.polygon(MUNICIPALITY_BOUNDARY, { color: '#94a3b8', weight: 2, dashArray: '6 4', fill: false, interactive: false }).addTo(zoneMap);

    communityZones.forEach((zone) => {
        if (zone.polygon.length < 3) return;
        const stats = eventStatsByCommunity.value.get(zone.id) ?? { count: 0, damage: 0 };
        const meta = densityMeta[densityLevelForCount(stats.count)];
        const popupHtml = `
            <div class="min-w-[150px] pb-1 text-center font-sans">
                <h4 class="mb-1 text-sm font-bold text-slate-800">${zone.name}</h4>
                <span class="inline-block rounded px-2 py-1 text-[10px] font-semibold" style="background-color:${meta.color}1a;color:${meta.color}">
                    ${stats.count} เหตุการณ์ · ${meta.label}
                </span>
            </div>
        `;
        L.polygon(zone.polygon, { color: meta.color, weight: 1, fillColor: meta.color, fillOpacity: 0.55 }).bindPopup(popupHtml).addTo(zoneMap!);
    });

    zoneMap.fitBounds(L.polygon(MUNICIPALITY_BOUNDARY).getBounds(), { padding: [10, 10] });
}

function destroyZoneMap() {
    zoneMap?.remove();
    zoneMap = null;
}

function setTableViewMode(mode: 'table' | 'map') {
    tableViewMode.value = mode;
    if (mode === 'map') {
        nextTick(() => initZoneMap());
    } else {
        destroyZoneMap();
    }
}

onUnmounted(destroyZoneMap);
</script>

<template>
    <Head title="เหตุการณ์ L&D" />

    <AdminLayout title="เหตุการณ์ L&D">
        <div class="mb-6">
            <h1 class="text-lg font-bold text-slate-800">เหตุการณ์_LD</h1>
            <p class="mt-1 text-sm text-slate-500">ทะเบียนเหตุการณ์ภัยพิบัติและข้อมูล Loss & Damage / Recovery ที่สังเกตได้</p>
        </div>

        <!-- Stat row -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-xs text-blue-600">
                        <i class="fa-solid fa-file-lines"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">เหตุการณ์ทั้งหมด</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ events.length }}</span
                    ><span class="text-[10px] text-slate-400">รายการ</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-xs text-emerald-600">
                        <i class="fa-solid fa-circle-check"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">พร้อมรวมผล</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ completeEvents.length }}</span
                    ><span class="text-[10px] text-slate-400">รายการ</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-xs text-amber-600">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ข้อมูลไม่ครบ</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ incompleteEvents.length }}</span
                    ><span class="text-[10px] text-slate-400">รายการ</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-xs text-amber-600">
                        <i class="fa-solid fa-coins"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">มูลค่าเสียหายรวม</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ (totalDamageThb / 1_000_000).toFixed(2) }}</span
                    ><span class="text-[10px] text-slate-400">ล้านบาท</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-50 text-xs text-red-600">
                        <i class="fa-solid fa-house-chimney-crack"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">บ้านเสียหาย</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalHousesDamaged }}</span
                    ><span class="text-[10px] text-slate-400">หลัง</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-purple-50 text-xs text-purple-600">
                        <i class="fa-solid fa-user-injured"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">บาดเจ็บ / เสียชีวิต</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ totalInjuries }} / {{ totalDeaths }}</span
                    ><span class="text-[10px] text-slate-400">ราย</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-xs text-emerald-600">
                        <i class="fa-solid fa-rotate"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Recovery เฉลี่ย</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ avgRecoveryScore }}</span
                    ><span class="text-[10px] text-slate-400">คะแนน</span>
                </p>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <button
                v-for="h in hazardOrder"
                :key="h"
                type="button"
                @click="toggleHazardFilter(h)"
                class="flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2 text-xs font-semibold transition-all"
                :class="
                    hazardEnabled[h]
                        ? `border-transparent ${hazardBadgeClass[h].bg} ${hazardBadgeClass[h].color}`
                        : 'border-slate-200 bg-white text-slate-400 hover:bg-slate-50'
                "
            >
                <i :class="hazardIcons[h]"></i>
                {{ hazardShortLabel[h] }}
            </button>

            <select
                v-model="filterVerification"
                class="shrink-0 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
            >
                <option value="all">สถานะตรวจสอบ: ทั้งหมด</option>
                <option v-for="v in verificationOptions" :key="v" :value="v">{{ v }}</option>
            </select>
            <div class="flex shrink-0 items-center gap-1 text-xs">
                <input
                    type="date"
                    v-model="filterStartDate"
                    class="rounded-xl border border-slate-200 bg-slate-50 px-1.5 py-2 text-center focus:border-blue-500 focus:bg-white focus:outline-none"
                />
                <span class="text-slate-400">–</span>
                <input
                    type="date"
                    v-model="filterEndDate"
                    class="rounded-xl border border-slate-200 bg-slate-50 px-1.5 py-2 text-center focus:border-blue-500 focus:bg-white focus:outline-none"
                />
            </div>
            <div class="relative min-w-[200px] flex-1">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-300"></i>
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="ค้นหา EVENT_ID, ชุมชน..."
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-8 pr-3 text-sm text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                />
            </div>
        </div>

        <!-- ตารางทะเบียนเหตุการณ์ -->
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                <h2 class="font-semibold text-slate-800">ตารางทะเบียนเหตุการณ์</h2>
                <div class="flex items-center rounded-lg border border-slate-200 bg-slate-100/80 p-0.5">
                    <button
                        type="button"
                        @click="setTableViewMode('table')"
                        class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-all"
                        :class="tableViewMode === 'table' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    >
                        <i class="fa-solid fa-table-list"></i>
                        ตาราง
                    </button>
                    <button
                        type="button"
                        @click="setTableViewMode('map')"
                        class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-all"
                        :class="tableViewMode === 'map' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    >
                        <i class="fa-solid fa-draw-polygon"></i>
                        ผังพื้นที่
                    </button>
                </div>
            </div>

            <template v-if="tableViewMode === 'table'">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">EVENT_ID</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">COMMUNITY_NAME</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">PRIMARY_HAZARD</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">EVENT_START</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">EVENT_END</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">L1_HOUSES</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">L2_INJ</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">L2_DEATHS</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">L3_DAMAGE_THB</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">L4_DAYS</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">R_OBSERVED</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">DATA_QUALITY</th>
                                <th class="whitespace-nowrap px-3 py-2 font-medium">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="event in pagedEvents" :key="event.id" class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-3 py-2 font-medium text-blue-600">{{ event.id }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ communityName(event.communityId) }}</td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="[hazardBadgeClass[event.primaryHazard].bg, hazardBadgeClass[event.primaryHazard].color]"
                                    >
                                        {{ hazardShortLabel[event.primaryHazard] }}
                                    </span>
                                    <span v-if="isMultiHazard(event)" class="ml-1 text-[9px] text-slate-400">+หลายภัย</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-slate-400">{{ event.eventStart }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-slate-400">{{ event.eventEnd }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ event.l1HousesDamaged }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ event.l2Injuries }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ event.l2Deaths }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">
                                    {{ event.l3DamageValueThb.toLocaleString('th-TH') }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ event.l4RecoveryDays }}</td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :style="{
                                            backgroundColor: recoveryBand(eventRecoveryScore(event)).color + '1a',
                                            color: recoveryBand(eventRecoveryScore(event)).color,
                                        }"
                                    >
                                        {{ eventRecoveryScore(event) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <span
                                        v-if="event.dataQuality"
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="dataQualityBadgeClass[event.dataQuality] ?? 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ event.dataQuality }}
                                    </span>
                                    <span v-else class="text-slate-300">–</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <div class="flex items-center gap-2.5 text-slate-400">
                                        <button type="button" title="แก้ไข" @click="openEditModal(event)" class="hover:text-blue-600">
                                            <i class="fa-regular fa-pen-to-square"></i>
                                        </button>
                                        <button type="button" title="ลบ" @click="destroyEvent(event)" class="hover:text-red-600">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="pagedEvents.length === 0">
                                <td colspan="13" class="px-3 py-10 text-center text-sm text-slate-400">ไม่พบเหตุการณ์ตามเงื่อนไขที่เลือก</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="filteredEvents.length > 0"
                    class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500"
                >
                    <span>
                        แสดง {{ (currentPage - 1) * pageSize + 1 }}-{{ Math.min(currentPage * pageSize, filteredEvents.length) }} จาก
                        {{ filteredEvents.length }} รายการ
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
            </template>

            <div v-else class="p-4">
                <p class="mb-3 text-[11px] text-amber-600">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                    ขอบเขตพื้นที่ชุมชนเป็นการประมาณชั่วคราว ระหว่างรอไฟล์ shapefile จริง — สีแสดงจำนวนเหตุการณ์ที่บันทึกไว้ตามตัวกรองปัจจุบัน
                </p>
                <div ref="zoneMapEl" class="h-[420px] w-full rounded-xl bg-slate-50"></div>
                <div class="mt-3 flex flex-wrap gap-3">
                    <span v-for="(meta, level) in densityMeta" :key="level" class="flex items-center gap-1.5 text-[11px] text-slate-500">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: meta.color }"></span>
                        {{ meta.label }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Summary cards — below the table, full width -->
        <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-4">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold text-slate-800">สถานะข้อมูล</h2>
                <DonutChart :segments="dataStatusSegments" center-label="รายการ" />
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="mb-1 font-semibold text-slate-800">แจกแจงประเภทภัย</h2>
                <p class="mb-3 text-xs text-slate-400">รายการที่พร้อมรวมผล</p>
                <BarChart :bars="hazardCompleteBars" />
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold text-slate-800">ผลกระทบสะสม</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <i class="fa-solid fa-house-chimney-crack mb-1 text-red-500"></i>
                        <p class="text-lg font-bold text-slate-800">{{ totalHousesDamaged }}</p>
                        <p class="text-[10px] text-slate-400">บ้านเสียหาย (หลัง)</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <i class="fa-solid fa-user-injured mb-1 text-purple-500"></i>
                        <p class="text-lg font-bold text-slate-800">{{ totalInjuries }}</p>
                        <p class="text-[10px] text-slate-400">บาดเจ็บ (ราย)</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <i class="fa-solid fa-cross mb-1 text-rose-600"></i>
                        <p class="text-lg font-bold text-slate-800">{{ totalDeaths }}</p>
                        <p class="text-[10px] text-slate-400">เสียชีวิต (ราย)</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <i class="fa-solid fa-calendar-day mb-1 text-blue-500"></i>
                        <p class="text-lg font-bold text-slate-800">{{ avgRecoveryDays }}</p>
                        <p class="text-[10px] text-slate-400">วันฟื้นตัวเฉลี่ย</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold text-slate-800">Recovery ที่สังเกตได้</h2>
                <div class="flex items-center gap-5">
                    <div class="relative h-20 w-20 shrink-0">
                        <svg viewBox="0 0 80 80" class="-rotate-90">
                            <circle cx="40" cy="40" :r="GAUGE_RADIUS" fill="none" stroke-width="8" class="stroke-slate-100" />
                            <circle
                                cx="40"
                                cy="40"
                                :r="GAUGE_RADIUS"
                                fill="none"
                                stroke="#14b8a6"
                                stroke-width="8"
                                stroke-linecap="round"
                                :stroke-dasharray="`${(avgRecoveryScore / 5) * GAUGE_CIRCUMFERENCE} ${GAUGE_CIRCUMFERENCE}`"
                            />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-[9px] text-slate-400">เฉลี่ย</span>
                            <span class="text-lg font-bold text-slate-800">{{ avgRecoveryScore }}</span>
                            <span class="text-[9px] text-slate-400">คะแนน</span>
                        </div>
                    </div>
                    <div class="flex-1 space-y-2">
                        <div v-for="row in recoveryRows" :key="row.key" class="flex items-center gap-2 text-xs">
                            <span class="w-[104px] shrink-0 truncate" :class="row.emphasized ? 'font-semibold text-slate-600' : 'text-slate-400'">{{
                                row.key
                            }}</span>
                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <span
                                    class="block h-full rounded-full"
                                    :class="row.emphasized ? 'bg-teal-500' : 'bg-emerald-500'"
                                    :style="{ width: (row.value / 5) * 100 + '%' }"
                                ></span>
                            </span>
                            <span class="w-8 shrink-0 text-right font-semibold text-slate-700">{{ row.value }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- กรอบข้อมูล L&D และ Recovery — field-dictionary reference -->
        <div class="mt-6 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold text-slate-800">กรอบข้อมูล L&D และ Recovery</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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
            <button
                type="button"
                @click="openCreateModal"
                class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
            >
                <i class="fa-solid fa-plus mr-1.5"></i>
                เพิ่มเหตุการณ์
            </button>
            <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fa-solid fa-upload mr-1.5"></i>
                อัปโหลดหลักฐาน
            </button>
            <button type="button" class="rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                <i class="fa-solid fa-floppy-disk mr-1.5"></i>
                บันทึกข้อมูลเหตุการณ์
            </button>
        </div>

        <!-- Create / Edit Modal -->
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="closeModal"
        >
            <div class="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ modalMode === 'create' ? 'บันทึกเหตุการณ์ใหม่' : `แก้ไขเหตุการณ์ ${form.id}` }}
                    </h3>
                    <button
                        type="button"
                        @click="closeModal"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="overflow-y-auto">
                    <div class="space-y-6 p-6">
                        <!-- กลุ่ม 1: ข้อมูลเหตุการณ์ -->
                        <div class="rounded-xl border border-slate-100 p-4">
                            <p class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลเหตุการณ์</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label :class="labelClass">ชุมชน</label>
                                    <select v-model.number="form.communityId" :class="inputClass">
                                        <option v-for="c in communities" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-400">ชื่อชุมชน (อ้างอิงอัตโนมัติ): {{ formCommunityName }}</p>
                                </div>
                                <div>
                                    <label :class="labelClass">ภัยหลัก (Primary Hazard)</label>
                                    <select v-model="form.primaryHazard" :class="inputClass">
                                        <option v-for="h in hazardOrder" :key="h" :value="h">{{ hazardShortLabel[h] }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="labelClass">วันที่เริ่มเหตุ</label>
                                    <input v-model="form.eventStart" type="date" required :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">วันที่สิ้นสุดเหตุ</label>
                                    <input v-model="form.eventEnd" type="date" required :class="inputClass" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label :class="labelClass">ภัยที่เกี่ยวข้อง (Related Hazards)</label>
                                    <div class="flex flex-wrap gap-3">
                                        <label
                                            v-for="h in hazardOrder.filter((x) => x !== form.primaryHazard)"
                                            :key="h"
                                            class="flex items-center gap-1.5 text-xs text-slate-600"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="form.relatedHazards.includes(h)"
                                                @change="toggleRelatedHazard(h)"
                                                class="h-3.5 w-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                            />
                                            {{ hazardShortLabel[h] }}
                                        </label>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">
                                        เหตุหลายภัย (อ้างอิงอัตโนมัติ): <span class="font-medium">{{ formMultiHazardFlag ? 'ใช่' : 'ไม่ใช่' }}</span>
                                    </p>
                                </div>
                                <div class="sm:col-span-2">
                                    <label :class="labelClass">คำบรรยายเหตุการณ์</label>
                                    <textarea v-model="form.description" rows="2" :class="inputClass"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- กลุ่ม 2: Loss & Damage -->
                        <div class="rounded-xl border border-slate-100 p-4">
                            <p class="mb-3 text-sm font-semibold text-slate-700">Loss & Damage</p>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <div>
                                    <label :class="labelClass">L1 บ้านเสียหาย (หลัง)</label>
                                    <input v-model.number="form.l1HousesDamaged" type="number" min="0" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">L2 บาดเจ็บ (คน)</label>
                                    <input v-model.number="form.l2Injuries" type="number" min="0" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">L2 เสียชีวิต (คน)</label>
                                    <input v-model.number="form.l2Deaths" type="number" min="0" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">L3 มูลค่าเสียหาย (บาท)</label>
                                    <input v-model.number="form.l3DamageValueThb" type="number" min="0" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">L4 ระยะเวลาฟื้นตัว (วัน)</label>
                                    <input v-model.number="form.l4RecoveryDays" type="number" min="0" :class="inputClass" />
                                </div>
                                <div class="flex items-end pb-2.5">
                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input
                                            type="checkbox"
                                            v-model="form.l5RepeatDamage"
                                            class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                        />
                                        L5 ความเสียหายซ้ำ
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- กลุ่ม 3: Recovery ที่สังเกตได้ -->
                        <div class="rounded-xl border border-slate-100 p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="text-sm font-semibold text-slate-700">Recovery ที่สังเกตได้</p>
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-600">
                                    R_OBSERVED_SCORE (คำนวณอัตโนมัติ): {{ formRecoveryScore }}
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                                <div>
                                    <label :class="labelClass">R1</label>
                                    <input v-model.number="form.r1Observed" type="number" min="0" max="5" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">R2</label>
                                    <input v-model.number="form.r2Observed" type="number" min="0" max="5" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">R3</label>
                                    <input v-model.number="form.r3Observed" type="number" min="0" max="5" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">R4</label>
                                    <input v-model.number="form.r4Observed" type="number" min="0" max="5" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">R5</label>
                                    <input v-model.number="form.r5Observed" type="number" min="0" max="5" :class="inputClass" />
                                </div>
                            </div>
                        </div>

                        <!-- กลุ่ม 4: หลักฐาน/ธรรมาภิบาล -->
                        <div class="rounded-xl border border-slate-100 p-4">
                            <p class="mb-3 text-sm font-semibold text-slate-700">หลักฐาน / ธรรมาภิบาล</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label :class="labelClass">แหล่งข้อมูล (Source)</label>
                                    <input v-model="form.source" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">รหัสหลักฐาน (Evidence)</label>
                                    <input v-model="form.evidence" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">วันที่บันทึกข้อมูล</label>
                                    <input v-model="form.dataDate" type="date" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">หน่วยงานรับผิดชอบ</label>
                                    <input v-model="form.responsibleAgency" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label :class="labelClass">สถานะตรวจสอบ</label>
                                    <select v-model="form.verificationStatus" :class="inputClass">
                                        <option v-for="v in verificationOptions" :key="v" :value="v">{{ v }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="labelClass">คุณภาพข้อมูล</label>
                                    <input v-model="form.dataQuality" type="text" :class="inputClass" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label :class="labelClass">หมายเหตุ</label>
                                    <textarea v-model="form.notes" rows="2" :class="inputClass"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-slate-100 bg-white px-6 py-4">
                        <button
                            type="button"
                            @click="closeModal"
                            class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                        >
                            บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
