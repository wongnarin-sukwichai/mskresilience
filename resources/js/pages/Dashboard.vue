<script setup lang="ts">
import BarChart from '@/components/admin/BarChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import { communityZones } from '@/data/communityZones';
import { hazardBadgeClass, hazardIcons, hazardOrder, hazardShortLabel, riskLevelFromScore, riskLevelMeta, seededValue } from '@/data/hazardMeta';
import { MAP_CENTER, MUNICIPALITY_BOUNDARY, communities, riskColors, riskNameTH, type Community } from '@/data/mahasarakham';
import { communityName, events } from '@/data/mockEvents';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fadeInGroup, fadeOutGroup } from '@/lib/leafletFade';
import { Head, Link } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

// Everything below except "จำนวนชุมชนทั้งหมด" (real, from communities.length) and the community
// list/coordinates is mock data — there's no hazard_assessments table yet (see CLAUDE.md). "เหตุการณ์
// ล่าสุด" reads the shared `events` store (data/mockEvents.ts) so it stays consistent with the full
// register on the เหตุการณ์ L&D page. Field names/terminology follow the HEVC data dictionary
// (mskresilience.xlsx) so this UI won't need relabeling once a real backend lands.

const communityRisks = communities.map((c) => {
    const hevc = Math.round(seededValue(c.id) * 10) / 10;
    return { ...c, hevc, level: riskLevelFromScore(hevc) };
});

// Static reference card recreating the "องค์ประกอบหลักของระบบ" (H-E-V-Capacity-Recovery-L&D) diagram the
// project owner supplied as a design reference — not computed from any data source.
const systemComponents = [
    {
        key: 'hazard',
        label: 'Hazard',
        color: '#2563eb',
        icon: 'fa-solid fa-triangle-exclamation',
        description: 'ความน่าจะเป็นและความรุนแรงของภัยพิบัติ',
    },
    {
        key: 'exposure',
        label: 'Exposure',
        color: '#f97316',
        icon: 'fa-solid fa-map-location-dot',
        description: 'การเปิดรับต่อพื้นที่หรือทรัพย์สินที่เสี่ยงภัย',
    },
    {
        key: 'vulnerability',
        label: 'Vulnerability',
        color: '#14b8a6',
        icon: 'fa-solid fa-shield-heart',
        description: 'ความเปราะบางทางกายภาพ สังคม และเศรษฐกิจ',
    },
    {
        key: 'capacity',
        label: 'Capacity',
        color: '#8b5cf6',
        icon: 'fa-solid fa-gear',
        description: 'ศักยภาพในการรับมือ ทรัพยากร และมาตรการเตรียมพร้อม',
    },
    {
        key: 'recovery',
        label: 'Recovery',
        color: '#22c55e',
        icon: 'fa-solid fa-rotate',
        description: 'ศักยภาพในการฟื้นตัวและกลับคืนสู่สภาพปกติ',
    },
    {
        key: 'ld',
        label: 'L&D',
        color: '#ef4444',
        icon: 'fa-solid fa-chart-line',
        description: 'ความเสียหายและผลกระทบที่เกิดขึ้นจากภัยพิบัติ',
    },
];

// ── Stat cards ───────────────────────────────────────────────────────────
const totalCommunities = communities.length;
const cumulativeEvents = computed(() => events.value.length);
const dataQualityPct = 81;

const avgHEVC = computed(() => (communityRisks.reduce((sum, c) => sum + c.hevc, 0) / communityRisks.length).toFixed(1));

const dominantHazardKey = computed<Community['activeRisk']>(() => {
    const counts: Partial<Record<Community['activeRisk'], number>> = {};
    communities.forEach((c) => {
        if (c.activeRisk === 'none') return;
        counts[c.activeRisk] = (counts[c.activeRisk] ?? 0) + 1;
    });
    let best: Community['activeRisk'] = 'flood';
    let bestCount = -1;
    (Object.entries(counts) as [Community['activeRisk'], number][]).forEach(([key, count]) => {
        if (count > bestCount) {
            best = key;
            bestCount = count;
        }
    });
    return best;
});

// ── คะแนนความเสี่ยงรายภัย (bar chart) — average mock HEVC per hazard type ──
const perHazardBars = computed(() =>
    hazardOrder.map((key) => {
        const group = communityRisks.filter((c) => c.activeRisk === key);
        const avg = group.length ? group.reduce((sum, c) => sum + c.hevc, 0) / group.length : 0;
        return { label: hazardShortLabel[key], value: Math.round(avg * 10) / 10, color: riskColors[key] };
    }),
);

// ── ชุมชนเสี่ยงสูงสุด ──
const topRiskCommunities = computed(() => [...communityRisks].sort((a, b) => b.hevc - a.hevc).slice(0, 5));

// ── เหตุการณ์ล่าสุด — latest 5 from the shared `events` store (data/mockEvents.ts), so this preview
// stays consistent with the full register on the เหตุการณ์ L&D page ──
type Severity = 'สูง' | 'ปานกลาง' | 'ต่ำ';

const severityClass: Record<Severity, string> = {
    สูง: 'bg-red-50 text-red-600',
    ปานกลาง: 'bg-orange-50 text-orange-600',
    ต่ำ: 'bg-slate-100 text-slate-500',
};
const statusDotClass = { ยืนยันแล้ว: 'bg-emerald-500', กำลังตรวจสอบ: 'bg-amber-500' } as const;
const statusTextClass = { ยืนยันแล้ว: 'text-emerald-600', กำลังตรวจสอบ: 'text-amber-600' } as const;

// L3_DAMAGE_VALUE_THB isn't a severity field itself — this is a simple display-only proxy (no severity
// column exists in the real เหตุการณ์_LD dictionary) so the "ระดับความรุนแรง" badge has something to show.
function severityFromDamage(thb: number): Severity {
    if (thb >= 3000000) return 'สูง';
    if (thb >= 500000) return 'ปานกลาง';
    return 'ต่ำ';
}

const thaiMonthsShort = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
function formatThaiDate(iso: string) {
    const d = new Date(iso);
    return `${d.getDate()} ${thaiMonthsShort[d.getMonth()]} ${d.getFullYear() + 543}`;
}

const recentEvents = computed(() =>
    [...events.value]
        .sort((a, b) => b.eventStart.localeCompare(a.eventStart))
        .slice(0, 5)
        .map((e) => ({
            id: e.id,
            hazard: e.primaryHazard,
            community: communityName(e.communityId),
            date: formatThaiDate(e.eventStart),
            severity: severityFromDamage(e.l3DamageValueThb),
            status: e.verificationStatus,
        })),
);

// ── Risk map ──
const mapEl = ref<HTMLDivElement | null>(null);
let map: L.Map | null = null;
let satelliteTileLayer: L.TileLayer;
let colorTileLayer: L.TileLayer;
let grayTileLayer: L.TileLayer;
let markersGroup: Partial<Record<Community['activeRisk'], L.FeatureGroup>> = {};

const mapStyle = ref<'satellite' | 'color' | 'gray'>('satellite');

function setMapStyle(style: 'satellite' | 'color' | 'gray') {
    if (!map) return;
    const layers = { satellite: satelliteTileLayer, color: colorTileLayer, gray: grayTileLayer };
    Object.entries(layers).forEach(([key, layer]) => {
        if (key !== style && map!.hasLayer(layer)) map!.removeLayer(layer);
    });
    layers[style].addTo(map);
    mapStyle.value = style;
}

const hazardToggles = hazardOrder.map((key) => ({ key, label: hazardShortLabel[key], icon: hazardIcons[key] }));
const hazardEnabled = reactive<Record<string, boolean>>(Object.fromEntries(hazardOrder.map((key) => [key, true])));

function applyHazardFilter() {
    if (!map) return;
    hazardOrder.forEach((key) => {
        const group = markersGroup[key];
        if (!group) return;
        const isShown = map!.hasLayer(group);
        if (hazardEnabled[key] && !isShown) {
            fadeInGroup(group, map!);
        } else if (!hazardEnabled[key] && isShown) {
            fadeOutGroup(group, map!, () => !hazardEnabled[key]);
        }
    });
}

function toggleHazard(key: string) {
    hazardEnabled[key] = !hazardEnabled[key];
    applyHazardFilter();
}

// "เลือกชุมชน" / "ช่วงวันที่ข้อมูล" are real, working controls — bound to state, but (per spec) not yet
// wired to actually filter the map/data. clearFilters() resets all three controls together.
const DEFAULT_START_DATE = '2026-01-01';
const DEFAULT_END_DATE = '2026-08-13';
const selectedCommunityId = ref<number | 'all'>('all');
const filterStartDate = ref(DEFAULT_START_DATE);
const filterEndDate = ref(DEFAULT_END_DATE);

function clearFilters() {
    hazardOrder.forEach((key) => (hazardEnabled[key] = true));
    applyHazardFilter();
    selectedCommunityId.value = 'all';
    filterStartDate.value = DEFAULT_START_DATE;
    filterEndDate.value = DEFAULT_END_DATE;
}

function initRiskMap() {
    if (!mapEl.value) return;

    map = L.map(mapEl.value, { zoomControl: false }).setView(MAP_CENTER, 13);

    satelliteTileLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
        maxZoom: 19,
    });

    colorTileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap',
        subdomains: 'abcd',
        maxZoom: 20,
    });

    grayTileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap',
        subdomains: 'abcd',
        maxZoom: 20,
    });

    satelliteTileLayer.addTo(map);

    L.polygon(MUNICIPALITY_BOUNDARY, {
        color: '#facc15',
        weight: 2.5,
        dashArray: '6 4',
        fill: false,
        interactive: false,
    }).addTo(map);

    L.control.zoom({ position: 'topright' }).addTo(map);

    markersGroup = {
        flood: L.featureGroup().addTo(map),
        fire: L.featureGroup().addTo(map),
        wind: L.featureGroup().addTo(map),
        heat: L.featureGroup().addTo(map),
        pm25: L.featureGroup().addTo(map),
        none: L.featureGroup().addTo(map),
    };

    const zoneById = new Map(communityZones.map((z) => [z.id, z]));

    communityRisks.forEach((com) => {
        const meta = riskLevelMeta[com.level];
        const popupHtml = `
            <div class="min-w-[160px] pb-1 text-center font-sans">
                <h4 class="mb-1 text-sm font-bold text-slate-800">${com.name}</h4>
                <p class="mb-1 text-xs text-slate-500">ภัยเด่น: ${riskNameTH[com.activeRisk]}</p>
                <span class="inline-block rounded px-2 py-1 text-[10px] font-semibold" style="background-color:${meta.color}1a;color:${meta.color}">
                    HEVC ${com.hevc} · ระดับ${meta.label}
                </span>
                <p class="mt-1.5 text-[10px] text-slate-400">*ขอบเขตพื้นที่เป็นการประมาณชั่วคราว รอไฟล์ shapefile จริง</p>
            </div>
        `;

        // Zone fill — see data/communityZones.ts for why this is a Voronoi approximation, not a real boundary.
        const zone = zoneById.get(com.id);
        if (zone && zone.polygon.length >= 3) {
            const zonePolygon = L.polygon(zone.polygon, {
                color: meta.color,
                weight: 1.5,
                fillColor: meta.color,
                fillOpacity: 0.45,
            });
            zonePolygon.bindPopup(popupHtml);
            markersGroup[com.activeRisk]?.addLayer(zonePolygon);
        }

        const icon = L.divIcon({
            className: '',
            html: `<div style="background-color:${meta.color};color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.25);">${com.id}</div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13],
            popupAnchor: [0, -13],
        });

        const marker = L.marker([com.lat, com.lng], { icon });
        marker.bindPopup(popupHtml);
        markersGroup[com.activeRisk]?.addLayer(marker);
    });
}

onMounted(() => initRiskMap());
onUnmounted(() => {
    map?.remove();
    map = null;
});
</script>

<template>
    <Head title="แดชบอร์ดผู้ดูแลระบบ" />

    <AdminLayout title="แดชบอร์ดผู้ดูแลระบบ">
        <div class="space-y-6">
            <!-- Stat cards — grouped 3+2 to match the map/side-column widths of the row below -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 xl:col-span-2">
                    <StatCard
                        label="จำนวนชุมชนทั้งหมด"
                        :value="totalCommunities"
                        unit="ชุมชน"
                        icon="fa-solid fa-users"
                        icon-bg="bg-blue-50"
                        icon-color="text-blue-600"
                    />
                    <StatCard
                        label="เหตุการณ์สะสม"
                        :value="cumulativeEvents"
                        unit="เหตุการณ์"
                        icon="fa-regular fa-bell"
                        icon-bg="bg-amber-50"
                        icon-color="text-amber-600"
                    />
                    <StatCard
                        label="ภัยเด่น"
                        :value="hazardShortLabel[dominantHazardKey]"
                        :icon="hazardIcons[dominantHazardKey]"
                        :icon-bg="hazardBadgeClass[dominantHazardKey].bg"
                        :icon-color="hazardBadgeClass[dominantHazardKey].color"
                    />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:col-span-1">
                    <StatCard
                        label="HEVC เฉลี่ย"
                        :value="avgHEVC"
                        unit="คะแนน"
                        icon="fa-solid fa-gauge-high"
                        icon-bg="bg-purple-50"
                        icon-color="text-purple-600"
                    />
                    <StatCard
                        label="คุณภาพข้อมูล"
                        :value="dataQualityPct"
                        unit="%"
                        icon="fa-solid fa-database"
                        icon-bg="bg-emerald-50"
                        icon-color="text-emerald-600"
                    />
                </div>
            </div>

            <!-- Risk map + side panels -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100 xl:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-semibold text-slate-800">แผนที่ความเสี่ยง อำเภอเมืองมหาสารคาม</h2>
                            <p class="mt-0.5 text-[11px] text-amber-600">
                                <i class="mr-1 fa-solid fa-triangle-exclamation"></i>
                                ขอบเขตพื้นที่ชุมชนเป็นการประมาณชั่วคราว ระหว่างรอไฟล์ shapefile จริงจากผู้ว่าจ้าง
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center rounded-lg border border-slate-200 bg-slate-100/80 p-0.5">
                                <button
                                    type="button"
                                    @click="setMapStyle('satellite')"
                                    class="whitespace-nowrap rounded-md px-2.5 py-1 text-[11px] font-semibold transition-all"
                                    :class="mapStyle === 'satellite' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    ดาวเทียม
                                </button>
                                <button
                                    type="button"
                                    @click="setMapStyle('color')"
                                    class="whitespace-nowrap rounded-md px-2.5 py-1 text-[11px] font-semibold transition-all"
                                    :class="mapStyle === 'color' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    แผนที่สี
                                </button>
                                <button
                                    type="button"
                                    @click="setMapStyle('gray')"
                                    class="whitespace-nowrap rounded-md px-2.5 py-1 text-[11px] font-semibold transition-all"
                                    :class="mapStyle === 'gray' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    ขาวดำ
                                </button>
                            </div>
                            <span class="text-xs whitespace-nowrap text-slate-400">อัปเดตล่าสุด 13 ส.ค. 2569</span>
                        </div>
                    </div>

                    <div class="relative h-[480px] overflow-hidden rounded-xl">
                        <div ref="mapEl" class="w-full h-full"></div>

                        <!-- Filter panel -->
                        <div
                            class="custom-scrollbar absolute left-3 top-3 z-[400] max-h-[420px] w-60 overflow-y-auto rounded-xl border border-slate-100 bg-white/95 p-4 shadow-lg backdrop-blur"
                        >
                            <div class="flex items-center gap-2 mb-3 text-sm font-semibold text-slate-800">
                                <i class="text-xs fa-solid fa-filter text-slate-400"></i>
                                ตัวกรองข้อมูล
                            </div>

                            <p class="mb-2 text-xs font-medium text-slate-500">เลือกภัย</p>
                            <div class="mb-4 space-y-2">
                                <label
                                    v-for="hazard in hazardToggles"
                                    :key="hazard.key"
                                    class="flex items-center justify-between gap-2 text-xs cursor-pointer text-slate-600"
                                >
                                    <span class="flex min-w-0 flex-1 items-center gap-1.5 truncate">
                                        <i :class="[hazard.icon, 'w-3.5 shrink-0 text-center text-slate-400']"></i>
                                        <span class="truncate">{{ hazard.label }}</span>
                                    </span>
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="hazardEnabled[hazard.key]"
                                        @click="toggleHazard(hazard.key)"
                                        class="relative h-5 transition-colors rounded-full w-9 shrink-0"
                                        :class="hazardEnabled[hazard.key] ? 'bg-blue-600' : 'bg-slate-200'"
                                    >
                                        <span
                                            class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform"
                                            :class="hazardEnabled[hazard.key] ? 'translate-x-4' : 'translate-x-0'"
                                        ></span>
                                    </button>
                                </label>
                            </div>

                            <p class="mb-1.5 text-xs font-medium text-slate-500">เลือกชุมชน</p>
                            <select
                                v-model="selectedCommunityId"
                                class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-600 focus:border-blue-500 focus:bg-white focus:outline-none"
                            >
                                <option value="all">ทั้งหมด ({{ totalCommunities }} ชุมชน)</option>
                                <option v-for="com in communities" :key="com.id" :value="com.id">{{ com.name }}</option>
                            </select>

                            <p class="mb-1.5 text-xs font-medium text-slate-500">ช่วงวันที่ข้อมูล</p>
                            <div class="mb-4 flex items-center gap-1.5 text-xs text-slate-500">
                                <input
                                    type="date"
                                    v-model="filterStartDate"
                                    class="w-0 min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-1.5 py-1.5 text-center focus:border-blue-500 focus:bg-white focus:outline-none"
                                />
                                <span>–</span>
                                <input
                                    type="date"
                                    v-model="filterEndDate"
                                    class="w-0 min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-1.5 py-1.5 text-center focus:border-blue-500 focus:bg-white focus:outline-none"
                                />
                            </div>

                            <button
                                type="button"
                                @click="clearFilters"
                                class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-slate-200 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-50"
                            >
                                <i class="fa-solid fa-rotate-left text-[10px]"></i>
                                ล้างตัวกรอง
                            </button>
                        </div>

                        <!-- Risk-level legend -->
                        <div
                            class="absolute bottom-3 right-3 z-[400] rounded-xl border border-slate-100 bg-white/95 px-3.5 py-3 text-xs shadow-lg backdrop-blur"
                        >
                            <p class="mb-1.5 font-semibold text-slate-700">ระดับความเสี่ยง</p>
                            <div class="space-y-1">
                                <span v-for="(meta, level) in riskLevelMeta" :key="level" class="flex items-center gap-1.5 text-slate-500">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: meta.color }"></span>
                                    {{ meta.label }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-6">
                    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100">
                        <h2 class="mb-4 font-semibold text-slate-800">คะแนนความเสี่ยงรายภัย</h2>
                        <BarChart :bars="perHazardBars" :max="100" />
                    </div>

                    <div class="flex-1 p-5 bg-white border shadow-sm rounded-2xl border-slate-100">
                        <h2 class="mb-4 font-semibold text-slate-800">ชุมชนเสี่ยงสูงสุด</h2>
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-slate-400">
                                <tr>
                                    <th class="pb-2 font-medium">อันดับ</th>
                                    <th class="pb-2 font-medium">ชุมชน</th>
                                    <th class="pb-2 font-medium text-right">คะแนน (HEVC)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(com, i) in topRiskCommunities" :key="com.id">
                                    <td class="py-2 text-slate-400">{{ i + 1 }}</td>
                                    <td class="py-2 font-medium text-slate-700">{{ com.name }}</td>
                                    <td class="py-2 font-semibold text-right" :style="{ color: riskLevelMeta[com.level].color }">{{ com.hevc }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- เหตุการณ์ล่าสุด + องค์ประกอบหลักของระบบ — side by side below the map row -->
            <div class="grid items-start grid-cols-1 gap-6 xl:grid-cols-2">
                <div class="overflow-hidden bg-white border shadow-sm rounded-2xl border-slate-100">
                    <div class="flex items-center justify-between p-5 border-b border-slate-100">
                        <h2 class="font-semibold text-slate-800">เหตุการณ์ล่าสุด</h2>
                        <Link :href="route('events-ld.index')" class="text-xs font-medium text-blue-600 hover:text-blue-700">ดูทั้งหมด</Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs tracking-wider uppercase bg-slate-50 text-slate-400">
                                <tr>
                                    <th class="px-5 py-3 font-medium">รหัสเหตุการณ์</th>
                                    <th class="px-5 py-3 font-medium">ประเภทภัย</th>
                                    <th class="px-5 py-3 font-medium">ชุมชน</th>
                                    <th class="px-5 py-3 font-medium">วันที่เกิดเหตุ</th>
                                    <th class="px-5 py-3 font-medium">ระดับความรุนแรง</th>
                                    <th class="px-5 py-3 font-medium">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="event in recentEvents" :key="event.id" class="hover:bg-slate-50">
                                    <td class="px-5 py-3 font-medium text-blue-600 whitespace-nowrap">{{ event.id }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5" :style="{ color: riskColors[event.hazard] }">
                                            <i :class="hazardIcons[event.hazard]"></i>
                                            {{ riskNameTH[event.hazard] }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-slate-600">{{ event.community }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-slate-400">{{ event.date }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="severityClass[event.severity]">{{
                                            event.severity
                                        }}</span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium" :class="statusTextClass[event.status]">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass[event.status]"></span>
                                            {{ event.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- องค์ประกอบหลักของระบบ — static reference card recreating the HEVC + Recovery + L&D component
                     diagram the project owner supplied. -->
                <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100">
                    <h2 class="mb-4 font-semibold text-slate-800">องค์ประกอบหลักของระบบ</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div
                            v-for="comp in systemComponents"
                            :key="comp.key"
                            class="flex flex-col items-center p-4 text-center border rounded-2xl border-slate-100 bg-slate-50/60"
                        >
                            <span
                                class="flex items-center justify-center mb-2 text-white rounded-full h-11 w-11"
                                :style="{ backgroundColor: comp.color }"
                            >
                                <i :class="[comp.icon, 'text-lg']"></i>
                            </span>
                            <p class="text-sm font-bold" :style="{ color: comp.color }">{{ comp.label }}</p>
                            <p class="mt-1 text-[11px] leading-snug text-slate-500">{{ comp.description }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 5px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
</style>
