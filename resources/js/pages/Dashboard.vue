<script setup lang="ts">
import DonutChart from '@/components/admin/DonutChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import TrendChart from '@/components/admin/TrendChart.vue';
import { MAP_CENTER, MUNICIPALITY_BOUNDARY, communities, riskColors } from '@/data/mahasarakham';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, onMounted, onUnmounted, ref } from 'vue';

// ── Stat cards ───────────────────────────────────────────────────────────
// "ชุมชนทั้งหมด" is real (drawn from the same 32-community dataset as the public map).
// The rest have no backing data source yet (no risk-point / alert / user-activity tables) — mock for now.
const totalCommunities = computed(() => communities.length);
const trackedRiskPoints = 27;
const todayAlertCount = 6;
const systemUserCount = 14;

// ── Risk-point breakdown (donut) — reuses the app-wide risk taxonomy/colors from Welcome.vue for consistency ──
const riskBreakdown = [
    { label: 'อุทกภัย', value: 8, color: riskColors.flood },
    { label: 'อัคคีภัย', value: 6, color: riskColors.fire },
    { label: 'คลื่นความร้อน', value: 5, color: riskColors.heat },
    { label: 'วาตภัย', value: 4, color: riskColors.wind },
    { label: 'PM 2.5', value: 2, color: riskColors.pm25 },
    { label: 'อื่นๆ', value: 2, color: riskColors.none },
];

// ── Monthly trend (line) — last 6 months ending this month ──
const thaiMonthsShort = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const now = new Date();
const trendCategories = Array.from({ length: 6 }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - (5 - i), 1);
    return thaiMonthsShort[d.getMonth()];
});
const trendSeries = [
    { label: 'อุทกภัย', color: riskColors.flood, data: [3, 4, 5, 6, 7, 8] },
    { label: 'อัคคีภัย', color: riskColors.fire, data: [2, 3, 3, 4, 5, 6] },
    { label: 'คลื่นความร้อน', color: riskColors.heat, data: [1, 2, 3, 5, 8, 11] },
    { label: 'อื่นๆ', color: riskColors.none, data: [1, 1, 2, 2, 3, 3] },
];

// ── Mock alerts / latest records — using real community names from the shared dataset ──
type Severity = 'สูง' | 'ปานกลาง' | 'ต่ำ';
type Status = 'ใหม่' | 'กำลังดำเนินการ';

const severityClass: Record<Severity, string> = {
    สูง: 'bg-red-50 text-red-600',
    ปานกลาง: 'bg-orange-50 text-orange-600',
    ต่ำ: 'bg-slate-100 text-slate-500',
};
const statusClass: Record<Status, string> = {
    ใหม่: 'bg-red-50 text-red-600',
    กำลังดำเนินการ: 'bg-emerald-50 text-emerald-600',
};

interface AlertRow {
    time: string;
    typeLabel: string;
    typeIcon: string;
    typeColor: string;
    detail: string;
    area: string;
    severity: Severity;
    status: Status;
}

const recentAlerts: AlertRow[] = [
    {
        time: '08:35',
        typeLabel: 'อุทกภัย',
        typeIcon: 'fa-solid fa-water',
        typeColor: riskColors.flood,
        detail: 'ระดับน้ำเพิ่มขึ้นอย่างรวดเร็ว',
        area: 'ชุมชนตักสิลา 2',
        severity: 'สูง',
        status: 'ใหม่',
    },
    {
        time: '07:42',
        typeLabel: 'PM 2.5',
        typeIcon: 'fa-solid fa-smog',
        typeColor: riskColors.pm25,
        detail: 'ค่าฝุ่น PM2.5 เกินมาตรฐาน 59 μg/m³',
        area: 'ชุมชนธัญญา 2',
        severity: 'ปานกลาง',
        status: 'ใหม่',
    },
    {
        time: '07:15',
        typeLabel: 'คลื่นความร้อน',
        typeIcon: 'fa-solid fa-temperature-arrow-up',
        typeColor: riskColors.heat,
        detail: 'อุณหภูมิสูงสุดวันนี้ 38.6°C',
        area: 'ชุมชนมหาชัย',
        severity: 'ปานกลาง',
        status: 'ใหม่',
    },
    {
        time: '06:20',
        typeLabel: 'อุทกภัย',
        typeIcon: 'fa-solid fa-water',
        typeColor: riskColors.flood,
        detail: 'น้ำท่วมขังในพื้นที่ลุ่มต่ำ',
        area: 'ชุมชนส่องใต้',
        severity: 'สูง',
        status: 'กำลังดำเนินการ',
    },
    {
        time: '05:50',
        typeLabel: 'วาตภัย',
        typeIcon: 'fa-solid fa-wind',
        typeColor: riskColors.wind,
        detail: 'ลมกระโชกแรง ต้นไม้ล้มทับสายไฟ',
        area: 'ชุมชนศรีสวัสดิ์ 2',
        severity: 'ต่ำ',
        status: 'กำลังดำเนินการ',
    },
];

interface LatestRow extends AlertRow {
    recordedBy: string;
    updatedAt: string;
}

const latestRecords: LatestRow[] = recentAlerts.map((row, i) => ({
    ...row,
    recordedBy: ['นายสมชาย ใจดี', 'น.ส.วิลาวัลย์ คำดี', 'นายประสิทธิ์ รัตน์', 'น.ส.กนกวรรณ สีนา', 'นายพงพล แสงดี'][i],
    updatedAt: `6 ส.ค. 2569 ${row.time}`,
}));

// ── Mini map preview ──
const mapEl = ref<HTMLDivElement | null>(null);
let map: L.Map | null = null;

function initMiniMap() {
    if (!mapEl.value) return;

    map = L.map(mapEl.value, { zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false }).setView(
        MAP_CENTER,
        13,
    );

    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
    }).addTo(map);

    L.polygon(MUNICIPALITY_BOUNDARY, {
        color: '#facc15',
        weight: 2,
        dashArray: '5 4',
        fill: false,
        interactive: false,
    }).addTo(map);

    communities.forEach((com) => {
        const color = riskColors[com.activeRisk];
        const icon = L.divIcon({
            className: '',
            html: `<div style="background-color:${color}40;color:${color};border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;border:1.5px solid ${color};box-shadow:0 2px 6px rgba(0,0,0,0.15);">${com.id}</div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13],
        });
        L.marker([com.lat, com.lng], { icon, interactive: false }).addTo(map!);
    });

    L.control.zoom({ position: 'topright' }).addTo(map);
}

onMounted(() => initMiniMap());
onUnmounted(() => {
    map?.remove();
    map = null;
});

const mapLegend = [
    { label: 'น้ำท่วม', color: riskColors.flood },
    { label: 'อัคคีภัย', color: riskColors.fire },
    { label: 'ลมพายุ', color: riskColors.wind },
    { label: 'ความร้อน', color: riskColors.heat },
    { label: 'PM2.5', color: riskColors.pm25 },
    { label: 'อื่นๆ', color: riskColors.none },
];
</script>

<template>
    <Head title="แดชบอร์ดผู้ดูแลระบบ" />

    <AdminLayout title="แดชบอร์ดผู้ดูแลระบบ">
        <div class="space-y-6">
            <!-- Stat cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="ชุมชนทั้งหมด"
                    :value="totalCommunities"
                    unit="ชุมชน"
                    icon="fa-solid fa-users"
                    icon-bg="bg-blue-50"
                    icon-color="text-blue-600"
                />
                <StatCard
                    label="จุดเสี่ยงที่ติดตาม"
                    :value="trackedRiskPoints"
                    unit="จุด"
                    icon="fa-solid fa-location-dot"
                    icon-bg="bg-red-50"
                    icon-color="text-red-600"
                />
                <StatCard
                    label="แจ้งเตือนวันนี้"
                    :value="todayAlertCount"
                    unit="รายการ"
                    icon="fa-regular fa-bell"
                    icon-bg="bg-orange-50"
                    icon-color="text-orange-600"
                />
                <StatCard
                    label="ผู้ใช้งานระบบ"
                    :value="systemUserCount"
                    unit="คน"
                    icon="fa-solid fa-user-group"
                    icon-bg="bg-emerald-50"
                    icon-color="text-emerald-600"
                />
            </div>

            <!-- Map overview + recent alerts -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm xl:col-span-2">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold text-slate-800">ภาพรวมแผนที่</h2>
                    </div>
                    <div class="relative h-80 overflow-hidden rounded-xl">
                        <div ref="mapEl" class="h-full w-full"></div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-500">
                            <span v-for="item in mapLegend" :key="item.label" class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: item.color }"></span>
                                {{ item.label }}
                            </span>
                        </div>
                        <Link href="/" class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700">
                            <i class="fa-solid fa-up-right-and-down-left-from-center text-xs"></i>
                            ดูแผนที่เต็มหน้า
                        </Link>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-semibold text-slate-800">แจ้งเตือนล่าสุด</h2>
                        <span class="text-xs font-medium text-blue-600">ดูทั้งหมด</span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="(alert, i) in recentAlerts" :key="i" class="flex items-start gap-3 py-3">
                            <span
                                class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                :style="{ backgroundColor: alert.typeColor + '1a', color: alert.typeColor }"
                            >
                                <i :class="[alert.typeIcon, 'text-xs']"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-700">{{ alert.detail }}</p>
                                <p class="text-xs text-slate-400">{{ alert.time }} · {{ alert.area }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="statusClass[alert.status]">
                                {{ alert.status }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Charts + quick menu -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">สัดส่วนประเภทความเสี่ยง</h2>
                    <DonutChart :segments="riskBreakdown" center-label="จุดเสี่ยง" />
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm xl:col-span-2">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold text-slate-800">แนวโน้มเหตุการณ์รายเดือน</h2>
                        <span class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-500">6 เดือนล่าสุด</span>
                    </div>
                    <TrendChart :categories="trendCategories" :series="trendSeries" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm xl:col-span-2">
                    <h2 class="mb-4 font-semibold text-slate-800">เมนูด่วน</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <button
                            v-for="item in [
                                { label: 'เพิ่มจุดเสี่ยง', sub: 'เพิ่มจุดเสี่ยงใหม่', icon: 'fa-solid fa-location-dot', color: 'text-blue-600', bg: 'bg-blue-50' },
                                { label: 'นำเข้าข้อมูล', sub: 'นำเข้าข้อมูลจากไฟล์', icon: 'fa-solid fa-cloud-arrow-up', color: 'text-emerald-600', bg: 'bg-emerald-50' },
                                { label: 'สร้างรายงาน', sub: 'สร้างรายงานสรุป', icon: 'fa-solid fa-file-lines', color: 'text-purple-600', bg: 'bg-purple-50' },
                                { label: 'จัดการผู้ใช้', sub: 'จัดการบัญชีผู้ใช้งาน', icon: 'fa-solid fa-user-gear', color: 'text-orange-600', bg: 'bg-orange-50' },
                            ]"
                            :key="item.label"
                            type="button"
                            title="อยู่ระหว่างการพัฒนา"
                            class="flex flex-col items-start gap-2 rounded-xl border border-slate-100 p-4 text-left transition-colors hover:bg-slate-50"
                        >
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg" :class="[item.bg, item.color]">
                                <i :class="item.icon"></i>
                            </span>
                            <span>
                                <span class="block text-sm font-semibold text-slate-700">{{ item.label }}</span>
                                <span class="block text-xs text-slate-400">{{ item.sub }}</span>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 text-sm text-slate-500 shadow-sm">
                    <h2 class="mb-3 font-semibold text-slate-800">สรุปสถานะ</h2>
                    <dl class="space-y-3">
                        <div class="flex items-center justify-between">
                            <dt>สถานะระบบ</dt>
                            <dd class="flex items-center gap-1.5 font-medium text-emerald-600">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                ปกติ
                            </dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt>เวอร์ชันระบบ</dt>
                            <dd class="font-medium text-slate-700">v2.1.0</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Latest records table -->
            <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 p-5">
                    <h2 class="font-semibold text-slate-800">รายการข้อมูลล่าสุด</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wider text-slate-400 uppercase">
                            <tr>
                                <th class="px-5 py-3 font-medium">ชุมชน</th>
                                <th class="px-5 py-3 font-medium">ประเภทความเสี่ยง</th>
                                <th class="px-5 py-3 font-medium">รายละเอียด</th>
                                <th class="px-5 py-3 font-medium">สถานะ</th>
                                <th class="px-5 py-3 font-medium">ระดับความเสี่ยง</th>
                                <th class="px-5 py-3 font-medium">ผู้บันทึก</th>
                                <th class="px-5 py-3 font-medium">วันที่อัปเดต</th>
                                <th class="px-5 py-3 font-medium">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(row, i) in latestRecords" :key="i" class="hover:bg-slate-50">
                                <td class="px-5 py-3 font-medium whitespace-nowrap text-slate-700">{{ row.area }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5" :style="{ color: row.typeColor }">
                                        <i :class="row.typeIcon"></i>
                                        {{ row.typeLabel }}
                                    </span>
                                </td>
                                <td class="max-w-xs truncate px-5 py-3 text-slate-500">{{ row.detail }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass[row.status]">{{ row.status }}</span>
                                </td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="severityClass[row.severity]">{{ row.severity }}</span>
                                </td>
                                <td class="px-5 py-3 whitespace-nowrap text-slate-500">{{ row.recordedBy }}</td>
                                <td class="px-5 py-3 whitespace-nowrap text-slate-400">{{ row.updatedAt }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-3 text-slate-400">
                                        <i class="fa-regular fa-eye cursor-pointer hover:text-slate-600"></i>
                                        <i class="fa-regular fa-pen-to-square cursor-pointer hover:text-blue-600"></i>
                                        <i class="fa-regular fa-trash-can cursor-pointer hover:text-red-600"></i>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                    <span>แสดง 1-5 จาก {{ trackedRiskPoints }} รายการ</span>
                    <div class="flex items-center gap-1">
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100">
                            <i class="fa-solid fa-chevron-left text-xs"></i>
                        </button>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 font-medium text-white">1</button>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100">2</button>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100">
                            <i class="fa-solid fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
