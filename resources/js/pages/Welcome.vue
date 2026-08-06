<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { MAP_CENTER, MAP_ZOOM, MUNICIPALITY_BOUNDARY, communities, riskColors, riskNameTH, type Community } from '@/data/mahasarakham';
import type { SharedData } from '@/types';
import L from 'leaflet';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

// Authenticated-state header chip — without this, the page showed a generic "เข้าสู่ระบบ" button no matter
// who was logged in, so there was no way to see you were signed in as a different account, or to log out.
const page = usePage<SharedData>();
const authUser = computed(() => page.props.auth.user);
const canAccessAdminPanel = computed(() => !!authUser.value && ['admin', 'staff'].includes(authUser.value.role));
const userMenuOpen = ref(false);
const userMenuRef = ref<HTMLElement | null>(null);

function handleUserMenuClickOutside(event: MouseEvent) {
    if (userMenuOpen.value && userMenuRef.value && !userMenuRef.value.contains(event.target as Node)) {
        userMenuOpen.value = false;
    }
}

interface RiskFilter {
    value: 'all' | 'flood' | 'fire' | 'wind' | 'heat' | 'pm25';
    label: string;
    sub?: string;
    icon: string;
    iconBg: string;
    iconColor: string;
    hoverBg: string;
    accentClass: string;
}

const riskFilters: RiskFilter[] = [
    {
        value: 'all',
        label: 'แสดงทั้งหมด',
        icon: 'fa-solid fa-layer-group',
        iconBg: 'bg-slate-200',
        iconColor: 'text-slate-600',
        hoverBg: 'hover:bg-slate-100',
        accentClass: 'text-slate-600 focus:ring-slate-500',
    },
    {
        value: 'flood',
        label: 'อุทกภัย',
        icon: 'fa-solid fa-water',
        iconBg: 'bg-blue-100',
        iconColor: 'text-blue-600',
        hoverBg: 'hover:bg-blue-50',
        accentClass: 'text-blue-600 focus:ring-blue-500 accent-blue-600',
    },
    {
        value: 'fire',
        label: 'อัคคีภัย',
        icon: 'fa-solid fa-fire',
        iconBg: 'bg-red-100',
        iconColor: 'text-red-600',
        hoverBg: 'hover:bg-red-50',
        accentClass: 'text-red-600 focus:ring-red-500 accent-red-500',
    },
    {
        value: 'wind',
        label: 'วาตภัย',
        icon: 'fa-solid fa-wind',
        iconBg: 'bg-teal-100',
        iconColor: 'text-teal-600',
        hoverBg: 'hover:bg-teal-50',
        accentClass: 'text-teal-600 focus:ring-teal-500 accent-teal-600',
    },
    {
        value: 'heat',
        label: 'คลื่นความร้อน',
        icon: 'fa-solid fa-temperature-arrow-up',
        iconBg: 'bg-orange-100',
        iconColor: 'text-orange-600',
        hoverBg: 'hover:bg-orange-50',
        accentClass: 'text-orange-600 focus:ring-orange-500 accent-orange-500',
    },
    {
        value: 'pm25',
        label: 'PM 2.5',
        sub: 'เฝ้าระวัง',
        icon: 'fa-solid fa-smog',
        iconBg: 'bg-purple-100',
        iconColor: 'text-purple-600',
        hoverBg: 'hover:bg-purple-50',
        accentClass: 'text-purple-600 focus:ring-purple-500 accent-purple-600',
    },
];

// Quick stats derived from community data
const floodCount = computed(() => communities.filter((c) => c.activeRisk === 'flood').length);
const fireCount = computed(() => communities.filter((c) => c.activeRisk === 'fire').length);
const windCount = computed(() => communities.filter((c) => c.activeRisk === 'wind').length);
const avgHeatC = 38;
const avgPm25 = 68;

// Top bar state
const currentDateText = ref('กำลังโหลดข้อมูล...');
let dateTimer: ReturnType<typeof setInterval> | null = null;

function updateDateText() {
    const date = new Date();
    const thaiMonths = [
        'มกราคม',
        'กุมภาพันธ์',
        'มีนาคม',
        'เมษายน',
        'พฤษภาคม',
        'มิถุนายน',
        'กรกฎาคม',
        'สิงหาคม',
        'กันยายน',
        'ตุลาคม',
        'พฤศจิกายน',
        'ธันวาคม',
    ];
    const day = date.getDate();
    const month = thaiMonths[date.getMonth()];
    const year = date.getFullYear() + 543; // Convert to Buddhist Era
    currentDateText.value = `${day} ${month} ${year}`;
}

// Map style toggle (satellite / color / grayscale) — satellite is the default view
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

// Risk filter state
const selectedRisk = ref<RiskFilter['value']>('all');

function applyRiskFilter(selected: RiskFilter['value']) {
    if (!map) return;
    Object.values(markersGroup).forEach((group) => {
        if (map!.hasLayer(group)) map!.removeLayer(group);
    });

    if (selected === 'all') {
        Object.values(markersGroup).forEach((group) => group.addTo(map!));
    } else if (markersGroup[selected]) {
        markersGroup[selected].addTo(map!);
    }
}

watch(selectedRisk, (value) => applyRiskFilter(value));

function focusMap(lat: number, lng: number) {
    map?.flyTo([lat, lng], 16, { animate: true, duration: 1.5 });
}

// Sidebar expand/collapse state
const sidebarExpanded = ref(false);

function activateSidebar() {
    if (sidebarExpanded.value) return;
    sidebarExpanded.value = true;
}

function deactivateSidebar() {
    sidebarExpanded.value = false;
}

// Login modal state — real authentication against Laravel's session-based auth (routes/auth.php).
// Self-registration is disabled system-wide, so this modal only ever signs in existing accounts.
const loginModalOpen = ref(false);
const loginBoxOpen = ref(false);
const modalMode = ref<'login' | 'forgot'>('login');
let modalTimer: ReturnType<typeof setTimeout> | null = null;

const loginForm = useForm({ email: '', password: '' });
const forgotForm = useForm({ email: '' });

function openLoginModal() {
    modalMode.value = 'login';
    loginForm.clearErrors();
    forgotForm.clearErrors();
    loginModalOpen.value = true;
    if (modalTimer) clearTimeout(modalTimer);
    modalTimer = setTimeout(() => {
        loginBoxOpen.value = true;
    }, 50);
}

function closeLoginModal() {
    loginBoxOpen.value = false;
    if (modalTimer) clearTimeout(modalTimer);
    modalTimer = setTimeout(() => {
        loginModalOpen.value = false;
    }, 300);
}

function closeLoginModalOutside(event: MouseEvent) {
    if ((event.target as HTMLElement).id === 'login-modal') {
        closeLoginModal();
    }
}

function showForgotPassword() {
    modalMode.value = 'forgot';
    loginForm.clearErrors();
}

function showLoginForm() {
    modalMode.value = 'login';
    forgotForm.clearErrors();
}

// Toast — reused for the "reset link sent" confirmation (login itself redirects to /dashboard on success,
// so it never needs the toast).
const toastVisible = ref(false);
const toastTitle = ref('');
const toastMessage = ref('');
let toastTimer: ReturnType<typeof setTimeout> | null = null;

function showToast(title: string, message: string) {
    toastTitle.value = title;
    toastMessage.value = message;
    toastVisible.value = true;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastVisible.value = false;
    }, 4000);
}

function submitLogin() {
    loginForm.post(route('login'), {
        preserveScroll: true,
        onFinish: () => loginForm.reset('password'),
    });
}

function submitForgotPassword() {
    forgotForm.post(route('password.email'), {
        preserveScroll: true,
        onSuccess: () => {
            forgotForm.reset();
            closeLoginModal();
            showToast('ส่งอีเมลแล้ว', 'หากมีบัญชีนี้ในระบบ เราได้ส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ไปยังอีเมลนี้แล้ว');
        },
    });
}

// Leaflet map (kept outside Vue's reactivity — Leaflet manages its own internal state)
const mapEl = ref<HTMLDivElement | null>(null);
let map: L.Map | null = null;
let satelliteTileLayer: L.TileLayer;
let colorTileLayer: L.TileLayer;
let grayTileLayer: L.TileLayer;
let markersGroup: Record<Community['activeRisk'], L.FeatureGroup> = {} as Record<Community['activeRisk'], L.FeatureGroup>;

function initMap() {
    if (!mapEl.value) return;

    map = L.map(mapEl.value, { zoomControl: false }).setView(MAP_CENTER, MAP_ZOOM);

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

    // Real municipal boundary line (เส้นแบ่งเขตเทศบาลเมืองมหาสารคาม) — see MUNICIPALITY_BOUNDARY above for source.
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

    communities.forEach((com) => {
        const fillColor = riskColors[com.activeRisk];
        const isRisky = com.activeRisk !== 'none';
        const shortName = com.name.replace('ชุมชน', '');

        const customIcon = L.divIcon({
            className: '',
            html: `
                <div style="background-color: ${fillColor}40; color: ${fillColor}; border-radius: 50%; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 9.5px; font-family: 'Anuphan', sans-serif; font-weight: 500; text-align: center; border: 2px solid ${fillColor}; box-shadow: 0 4px 12px rgba(0,0,0,0.08); line-height: 1.05; padding: 2px; transition: all 0.3s ease; backdrop-filter: blur(2px);" class="cursor-pointer hover:scale-110">
                    ${shortName}
                </div>
            `,
            iconSize: [44, 44],
            iconAnchor: [22, 22],
            popupAnchor: [0, -22],
        });

        const marker = L.marker([com.lat, com.lng], { icon: customIcon });

        const badgeHtml = isRisky
            ? `<span class="inline-block px-2 py-1 text-[10px] font-semibold rounded bg-slate-100 border mt-2" style="color: ${fillColor}; border-color: ${fillColor}">ความเสี่ยงปัจจุบัน: ${riskNameTH[com.activeRisk]}</span>`
            : `<span class="inline-block px-2 py-1 text-[10px] font-semibold rounded bg-slate-100 border border-slate-300 text-slate-500 mt-2">สถานะปกติ</span>`;

        marker.bindPopup(`
            <div class="font-sans min-w-[150px] text-center pb-1">
                <h4 class="font-bold text-slate-800 text-sm mb-0.5">${com.name}</h4>
                <p class="text-xs text-slate-500">อ.เมือง จ.มหาสารคาม</p>
                ${badgeHtml}
            </div>
        `);

        markersGroup[com.activeRisk]?.addLayer(marker);
    });
}

onMounted(() => {
    document.body.classList.add('overflow-hidden');
    updateDateText();
    initMap();
    dateTimer = setInterval(updateDateText, 60000);
    document.addEventListener('click', handleUserMenuClickOutside);
});

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
    if (dateTimer) clearInterval(dateTimer);
    if (modalTimer) clearTimeout(modalTimer);
    if (toastTimer) clearTimeout(toastTimer);
    document.removeEventListener('click', handleUserMenuClickOutside);
    map?.remove();
    map = null;
});
</script>

<template>
    <Head title="หน้าแรก" />

    <div class="fixed inset-0 overflow-hidden font-sans text-slate-800 antialiased selection:bg-blue-200">
        <div ref="mapEl" id="map" class="absolute inset-0 z-10 h-full w-full"></div>

        <!-- Top Bar -->
        <header
            class="fixed top-0 left-0 z-[60] flex h-[72px] w-full items-center justify-between border-b border-slate-200 bg-white/95 px-6 shadow-sm backdrop-blur-md transition-all lg:px-8"
        >
            <div class="flex shrink-0 items-center gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white shadow-inner">
                    <i class="fa-solid fa-shield-halved text-xl"></i>
                </div>
                <div class="flex flex-col">
                    <h1 class="text-[17px] leading-tight font-bold whitespace-nowrap text-slate-800 lg:text-lg">ระบบบริหารข้อมูลความเสี่ยง</h1>
                    <p class="mt-0.5 text-[11px] font-medium whitespace-nowrap text-slate-500 lg:text-xs">
                        ความเปราะบางของชุมชน อ.เมือง จ.มหาสารคาม
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-3 lg:gap-5">
                <!-- Map Style Toggle -->
                <div class="hidden shrink-0 items-center rounded-xl border border-slate-200 bg-slate-100/80 p-1 md:flex">
                    <button
                        type="button"
                        @click="setMapStyle('satellite')"
                        class="rounded-lg px-4 py-1.5 text-xs font-semibold whitespace-nowrap transition-all"
                        :class="mapStyle === 'satellite' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    >
                        ดาวเทียม
                    </button>
                    <button
                        type="button"
                        @click="setMapStyle('color')"
                        class="rounded-lg px-4 py-1.5 text-xs font-semibold whitespace-nowrap transition-all"
                        :class="mapStyle === 'color' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    >
                        แผนที่สี
                    </button>
                    <button
                        type="button"
                        @click="setMapStyle('gray')"
                        class="rounded-lg px-4 py-1.5 text-xs font-semibold whitespace-nowrap transition-all"
                        :class="mapStyle === 'gray' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    >
                        ขาวดำ
                    </button>
                </div>

                <!-- Current Date -->
                <div class="hidden shrink-0 items-center rounded-full border border-slate-200 bg-slate-100/80 px-4 py-2 transition-all lg:flex">
                    <i class="fa-regular fa-calendar-check text-sm text-slate-400"></i>
                    <span class="ml-2 text-xs font-semibold text-slate-700 lg:text-sm">{{ currentDateText }}</span>
                </div>

                <!-- ปุ่มเข้าสู่ระบบ / สถานะผู้ใช้ที่ล็อกอินอยู่ -->
                <button
                    v-if="!authUser"
                    type="button"
                    @click="openLoginModal"
                    class="flex shrink-0 items-center gap-2 rounded-full bg-blue-600 px-6 py-2 text-xs font-semibold whitespace-nowrap text-white shadow-sm transition-all hover:bg-blue-700 active:scale-95 lg:text-sm"
                >
                    <i class="fa-solid fa-user-lock"></i>
                    เข้าสู่ระบบ
                </button>

                <div v-else ref="userMenuRef" class="relative shrink-0">
                    <button
                        type="button"
                        @click="userMenuOpen = !userMenuOpen"
                        class="flex items-center gap-2 rounded-full bg-slate-100 py-1.5 pr-3 pl-1.5 text-xs font-semibold text-slate-700 transition-all hover:bg-slate-200 lg:text-sm"
                    >
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">
                            {{ authUser.name.charAt(0) }}
                        </span>
                        <span class="hidden max-w-[10rem] truncate lg:inline">{{ authUser.name }}</span>
                        <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                    </button>

                    <div
                        v-if="userMenuOpen"
                        class="absolute right-0 z-10 mt-2 w-56 overflow-hidden rounded-xl border border-slate-100 bg-white py-1 text-left shadow-lg"
                    >
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="truncate text-sm font-semibold text-slate-800">{{ authUser.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ authUser.email }}</p>
                        </div>
                        <Link
                            v-if="canAccessAdminPanel"
                            :href="route('dashboard')"
                            class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-gauge w-4 text-center text-slate-400"></i>
                            ไปที่แดชบอร์ด
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50"
                        >
                            <i class="fa-solid fa-right-from-bracket w-4 text-center"></i>
                            ออกจากระบบ
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <!-- Floating Sidebar -->
        <aside
            id="main-sidebar"
            class="fixed top-20 left-4 z-[50] flex flex-col overflow-hidden rounded-2xl border border-white/50 bg-white/60 shadow-lg backdrop-blur-md hover:bg-white/95 hover:shadow-xl"
            :class="sidebarExpanded ? 'expanded h-[calc(100vh-6rem)] w-80' : 'w-16 cursor-pointer'"
            @click="activateSidebar"
        >
            <!-- Sidebar Header (Expanded Mode) -->
            <div v-if="sidebarExpanded" class="relative flex h-[65px] items-center justify-between border-b border-slate-100/50 p-4">
                <h2 class="flex items-center font-semibold whitespace-nowrap text-slate-800">
                    <i class="fa-solid fa-layer-group mr-2 text-blue-500"></i> ชั้นข้อมูล (Layers)
                </h2>
                <button
                    type="button"
                    class="rounded-full p-1 text-slate-400 transition-opacity hover:text-slate-600"
                    @click.stop="deactivateSidebar"
                >
                    <i class="fa-solid fa-compress text-sm"></i>
                </button>
            </div>

            <!-- Collapsed Icons View -->
            <div v-else class="flex w-full flex-col items-center gap-4 py-4">
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500 shadow-sm">
                    <i class="fa-solid fa-layer-group text-sm"></i>
                </div>
                <div class="h-px w-4 bg-slate-200"></div>

                <div
                    v-for="rf in riskFilters.filter((r) => r.value !== 'all')"
                    :key="rf.value"
                    class="flex h-8 w-8 items-center justify-center rounded-md"
                    :class="[rf.iconBg.replace('100', '50'), rf.iconColor.replace('600', '500')]"
                    :title="rf.label"
                >
                    <i :class="[rf.icon, 'text-sm']"></i>
                </div>
            </div>

            <!-- Sidebar Content (Scrollable) -->
            <div v-if="sidebarExpanded" class="custom-scrollbar flex-1 overflow-y-auto p-4">
                <!-- SECTION 1: Risks -->
                <div class="mb-6">
                    <h3 class="mb-3 text-xs font-bold tracking-wider text-slate-400 uppercase">ประเภทความเสี่ยง (Risks)</h3>
                    <div class="grid grid-cols-1 gap-2">
                        <label
                            v-for="rf in riskFilters"
                            :key="rf.value"
                            class="group/item relative z-20 flex cursor-pointer items-center rounded-lg border border-slate-100 bg-slate-50/50 p-2.5 transition-colors"
                            :class="rf.hoverBg"
                        >
                            <div
                                class="mr-3 flex h-8 w-8 items-center justify-center rounded-md shadow-sm transition-transform group-hover/item:scale-110"
                                :class="[rf.iconBg, rf.iconColor]"
                            >
                                <i :class="rf.icon"></i>
                            </div>
                            <div class="flex-1">
                                <span class="text-sm font-medium text-slate-700">{{ rf.label }}</span>
                                <span v-if="rf.sub" class="block text-[10px] font-semibold text-red-500">{{ rf.sub }}</span>
                            </div>
                            <input
                                type="radio"
                                name="riskFilter"
                                :value="rf.value"
                                v-model="selectedRisk"
                                class="h-4 w-4 border-gray-300"
                                :class="rf.accentClass"
                            />
                        </label>
                    </div>
                </div>

                <!-- SECTION 2: Communities List -->
                <div>
                    <h3 class="mb-3 text-xs font-bold tracking-wider text-slate-400 uppercase">พื้นที่ 32 ชุมชน (เทศบาลเมืองมหาสารคาม)</h3>
                    <ul class="relative z-20 space-y-1">
                        <li
                            v-for="(com, index) in communities"
                            :key="com.id"
                            class="group/list flex cursor-pointer items-center justify-between rounded border border-transparent p-2 text-sm transition-colors hover:border-slate-100 hover:bg-slate-50"
                            @click.stop="focusMap(com.lat, com.lng)"
                        >
                            <div class="flex items-center">
                                <span class="w-4 text-xs text-slate-400">{{ index + 1 }}.</span>
                                <span class="ml-2 text-slate-700 transition-colors group-hover/list:text-blue-600">{{ com.name }}</span>
                            </div>
                            <div class="h-2.5 w-2.5 rounded-full shadow-sm" :style="{ backgroundColor: riskColors[com.activeRisk] }"></div>
                        </li>
                    </ul>
                </div>
            </div>
        </aside>

        <!-- Quick Stats Overlay -->
        <div
            class="fixed right-6 bottom-6 z-[40] hidden flex-nowrap justify-end gap-2 pointer-events-none transition-all duration-400 ease-in-out lg:flex xl:gap-3"
            :class="sidebarExpanded ? 'left-[22rem]' : 'left-[5rem]'"
        >
            <div
                class="min-w-[120px] max-w-[160px] flex-1 shrink cursor-default rounded-xl border border-slate-100 bg-white/90 p-3 shadow-lg backdrop-blur-md transition-transform pointer-events-auto hover:-translate-y-1 xl:p-4"
            >
                <div class="mb-2 flex items-start justify-between">
                    <p class="text-[11px] leading-tight font-medium text-slate-500 xl:text-[12px]">พื้นที่เสี่ยง<br />อุทกภัย</p>
                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-blue-50 text-blue-600">
                        <i class="fa-solid fa-water text-xs"></i>
                    </div>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="text-xl font-bold text-slate-800 xl:text-2xl">{{ floodCount }}</span>
                    <span class="text-[10px] text-slate-500 xl:text-xs">ชุมชน</span>
                </div>
            </div>

            <div
                class="min-w-[120px] max-w-[160px] flex-1 shrink cursor-default rounded-xl border border-slate-100 bg-white/90 p-3 shadow-lg backdrop-blur-md transition-transform pointer-events-auto hover:-translate-y-1 xl:p-4"
            >
                <div class="mb-2 flex items-start justify-between">
                    <p class="text-[11px] leading-tight font-medium text-slate-500 xl:text-[12px]">พื้นที่เสี่ยง<br />อัคคีภัย</p>
                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-red-50 text-red-600">
                        <i class="fa-solid fa-fire text-xs"></i>
                    </div>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="text-xl font-bold text-slate-800 xl:text-2xl">{{ fireCount }}</span>
                    <span class="text-[10px] text-slate-500 xl:text-xs">ชุมชน</span>
                </div>
            </div>

            <div
                class="min-w-[120px] max-w-[160px] flex-1 shrink cursor-default rounded-xl border border-slate-100 bg-white/90 p-3 shadow-lg backdrop-blur-md transition-transform pointer-events-auto hover:-translate-y-1 xl:p-4"
            >
                <div class="mb-2 flex items-start justify-between">
                    <p class="text-[11px] leading-tight font-medium text-slate-500 xl:text-[12px]">พื้นที่เสี่ยง<br />วาตภัย</p>
                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-teal-50 text-teal-600">
                        <i class="fa-solid fa-wind text-xs"></i>
                    </div>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="text-xl font-bold text-slate-800 xl:text-2xl">{{ windCount }}</span>
                    <span class="text-[10px] text-slate-500 xl:text-xs">ชุมชน</span>
                </div>
            </div>

            <div
                class="min-w-[120px] max-w-[160px] flex-1 shrink cursor-default rounded-xl border border-slate-100 bg-white/90 p-3 shadow-lg backdrop-blur-md transition-transform pointer-events-auto hover:-translate-y-1 xl:p-4"
            >
                <div class="mb-2 flex items-start justify-between">
                    <p class="text-[11px] leading-tight font-medium text-slate-500 xl:text-[12px]">คลื่นความร้อน<br />เฉลี่ย</p>
                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-orange-50 text-orange-600">
                        <i class="fa-solid fa-temperature-arrow-up text-xs"></i>
                    </div>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="text-xl font-bold text-slate-800 xl:text-2xl">{{ avgHeatC }}</span>
                    <span class="text-[10px] text-slate-500 xl:text-xs">°C</span>
                </div>
            </div>

            <div
                class="min-w-[120px] max-w-[160px] flex-1 shrink cursor-default rounded-xl border border-slate-100 bg-white/90 p-3 shadow-lg backdrop-blur-md transition-transform pointer-events-auto hover:-translate-y-1 xl:p-4"
            >
                <div class="mb-2 flex items-start justify-between">
                    <p class="text-[11px] leading-tight font-medium text-slate-500 xl:text-[12px]">ค่าฝุ่น PM 2.5<br />เฉลี่ย</p>
                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-purple-50 text-purple-600">
                        <i class="fa-solid fa-smog text-xs"></i>
                    </div>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="text-xl font-bold text-slate-800 xl:text-2xl">{{ avgPm25 }}</span>
                    <span class="ml-1 text-[10px] font-medium text-red-500 xl:text-[11px]">(เฝ้าระวัง)</span>
                </div>
            </div>
        </div>

        <!-- Login Modal -->
        <div
            id="login-modal"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm transition-all duration-300"
            :class="loginModalOpen ? 'opacity-100' : 'pointer-events-none opacity-0'"
            @click="closeLoginModalOutside"
        >
            <div
                class="w-full max-w-md transform overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl transition-all duration-300"
                :class="loginBoxOpen ? 'scale-100 opacity-100' : 'scale-95 opacity-0'"
                @click.stop
            >
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
                            <i class="fa-solid fa-unlock-keyhole text-sm"></i>
                        </span>
                        <h3 class="text-base font-semibold text-slate-800">
                            {{ modalMode === 'login' ? 'เข้าสู่ระบบการทำงาน' : 'ลืมรหัสผ่าน' }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        @click="closeLoginModal"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <!-- Login form -->
                <form v-if="modalMode === 'login'" @submit.prevent="submitLogin" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase">อีเมล (Email)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <i class="fa-regular fa-envelope"></i>
                            </span>
                            <input
                                type="email"
                                v-model="loginForm.email"
                                required
                                autocomplete="email"
                                placeholder="example@domain.com"
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pr-4 pl-9 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                            />
                        </div>
                        <p v-if="loginForm.errors.email" class="mt-1.5 text-xs text-red-600">{{ loginForm.errors.email }}</p>
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <label class="block text-xs font-semibold tracking-wider text-slate-500 uppercase">รหัสผ่าน (Password)</label>
                            <button type="button" @click="showForgotPassword" class="text-xs font-medium text-blue-600 hover:text-blue-700">
                                ลืมรหัสผ่าน?
                            </button>
                        </div>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <i class="fa-solid fa-lock"></i>
                            </span>
                            <input
                                type="password"
                                v-model="loginForm.password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pr-4 pl-9 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                            />
                        </div>
                        <p v-if="loginForm.errors.password" class="mt-1.5 text-xs text-red-600">{{ loginForm.errors.password }}</p>
                    </div>

                    <div class="relative my-5">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-slate-200"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="bg-white px-3 text-xs font-medium text-slate-400">หรือ</span>
                        </div>
                    </div>

                    <div>
                        <button
                            type="button"
                            disabled
                            title="เร็ว ๆ นี้"
                            class="flex w-full cursor-not-allowed items-center justify-center gap-3 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-400 opacity-60"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24">
                                <path
                                    fill="#EA4335"
                                    d="M12 5.04c1.62 0 3.08.56 4.22 1.65l3.15-3.15C17.45 1.74 14.93 1 12 1 7.37 1 3.4 3.66 1.48 7.55l3.82 2.96c.92-2.76 3.5-4.47 6.7-4.47z"
                                />
                                <path
                                    fill="#4285F4"
                                    d="M23.49 12.27c0-.81-.07-1.59-.2-2.34H12v4.44h6.44c-.28 1.47-1.11 2.71-2.35 3.54l3.66 2.84c2.14-1.97 3.38-4.87 3.38-8.48z"
                                />
                                <path
                                    fill="#FBBC05"
                                    d="M5.3 14.91c-.24-.72-.38-1.49-.38-2.28 0-.79.14-1.56.38-2.28L1.48 7.39C.53 9.3 0 11.45 0 13.73c0 2.28.53 4.43 1.48 6.34l3.82-3.16z"
                                />
                                <path
                                    fill="#34A853"
                                    d="M12 23c3.24 0 5.97-1.07 7.96-2.91l-3.66-2.84c-1.01.68-2.31 1.08-3.9 1.08-3.2 0-5.78-1.71-6.75-4.47L1.48 16.82C3.4 20.71 7.37 23 12 23z"
                                />
                            </svg>
                            เข้าระบบด้วยบัญชี @gmail
                        </button>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button
                            type="button"
                            @click="closeLoginModal"
                            class="rounded-xl px-4 py-2 text-sm text-slate-500 transition-all hover:bg-slate-50 hover:text-slate-800"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            :disabled="loginForm.processing"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-blue-100 transition-all hover:bg-blue-700 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ loginForm.processing ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}
                        </button>
                    </div>
                </form>

                <!-- Forgot-password form -->
                <form v-else @submit.prevent="submitForgotPassword" class="space-y-4 p-6">
                    <p class="text-sm text-slate-500">กรอกอีเมลที่ใช้เข้าสู่ระบบ เราจะส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ไปให้ทางอีเมล</p>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase">อีเมล (Email)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <i class="fa-regular fa-envelope"></i>
                            </span>
                            <input
                                type="email"
                                v-model="forgotForm.email"
                                required
                                autocomplete="email"
                                placeholder="example@domain.com"
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pr-4 pl-9 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                            />
                        </div>
                        <p v-if="forgotForm.errors.email" class="mt-1.5 text-xs text-red-600">{{ forgotForm.errors.email }}</p>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        <button type="button" @click="showLoginForm" class="text-sm text-slate-500 transition-all hover:text-slate-800">
                            &larr; กลับไปเข้าสู่ระบบ
                        </button>
                        <button
                            type="submit"
                            :disabled="forgotForm.processing"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm shadow-blue-100 transition-all hover:bg-blue-700 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ forgotForm.processing ? 'กำลังส่ง...' : 'ส่งลิงก์รีเซ็ตรหัสผ่าน' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Success Toast -->
        <div
            class="fixed top-24 left-1/2 z-[100] flex w-full max-w-sm -translate-x-1/2 items-center gap-3 rounded-2xl bg-slate-900 px-5 py-3 text-white shadow-2xl transition-all duration-300"
            :class="toastVisible ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-[-20px] opacity-0'"
        >
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-400">
                <i class="fa-solid fa-circle-check text-base"></i>
            </span>
            <div>
                <p class="text-sm font-semibold">{{ toastTitle }}</p>
                <p class="text-xs text-slate-400">{{ toastMessage }}</p>
            </div>
        </div>
    </div>
</template>

<style>
/* Customize scrollbar for sidebar */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(241, 245, 249, 0.5);
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Hide map attribution for a cleaner dashboard look */
.leaflet-control-attribution {
    background: rgba(255, 255, 255, 0.7) !important;
    font-family: 'Anuphan', sans-serif !important;
    font-size: 10px !important;
}
.leaflet-control-zoom {
    border: none !important;
    box-shadow:
        0 4px 6px -1px rgb(0 0 0 / 0.1),
        0 2px 4px -2px rgb(0 0 0 / 0.1) !important;
    margin-top: 80px !important; /* Push down to clear top bar */
}

/* Sidebar State Styles */
#main-sidebar {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
#main-sidebar:not(.expanded)::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 10;
    cursor: pointer;
}
</style>
