<script setup lang="ts">
// หน้า 1 เกี่ยวกับระบบและการอ่านข้อมูล (spec v5 §1.1–1.4). Owner decisions 2026-11-10:
// - Data status shows the 3 states the system really has. There is no admin review, so no "VERIFIED/รอตรวจสอบ".
// - The หน่วยงานผู้พัฒนาระบบ block (at the bottom) uses the owner's text verbatim.
// - The header uses the owner's banner image (public/img/banner.webp, 2026-11-10).
// Text follows the spec's wording; keep it in sync if the spec changes.
import Reveal from '@/components/admin/Reveal.vue';
import { RISK_LEVEL_KEYS, SCORE_PALETTE, formatThaiDate, riskLevels } from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    dataDate: string | null;
    communityCount: number;
    criteriaSets: { name: string; start_fiscal_year: number }[];
}>();

const TERMS = [
    {
        tech: 'HEVC',
        label: 'ระดับความเสี่ยง',
        text: 'คะแนนสรุปความเสี่ยงของภัยแต่ละประเภท',
        icon: 'fa-solid fa-triangle-exclamation',
        color: 'text-red-500 bg-red-50',
    },
    {
        tech: 'Exposure',
        label: 'ผู้ที่อาจได้รับผลกระทบ',
        text: 'จำนวน/สัดส่วนประชาชนที่อยู่ในขอบเขตการเปิดรับภัย',
        icon: 'fa-solid fa-people-group',
        color: 'text-orange-500 bg-orange-50',
    },
    {
        tech: 'Vulnerability',
        label: 'ความเปราะบาง',
        text: 'ปัจจัยที่ทำให้ได้รับผลกระทบได้มากขึ้น',
        icon: 'fa-solid fa-user-shield',
        color: 'text-violet-600 bg-violet-50',
    },
    {
        tech: 'Capacity',
        label: 'ความพร้อมรับมือ',
        text: 'ความพร้อมของคน ระบบ อุปกรณ์ พื้นที่ และเครือข่าย',
        icon: 'fa-solid fa-shield-heart',
        color: 'text-emerald-600 bg-emerald-50',
    },
    { tech: 'Recovery', label: 'การฟื้นฟู', text: 'ความพร้อมและผลการฟื้นตัวหลังเหตุ', icon: 'fa-solid fa-rotate', color: 'text-blue-600 bg-blue-50' },
    {
        tech: 'Loss & Damage',
        label: 'ความสูญเสียและความเสียหาย',
        text: 'ผลกระทบจริง เช่น บ้านเสียหาย บาดเจ็บ เสียชีวิต และมูลค่าความเสียหาย',
        icon: 'fa-solid fa-house-crack',
        color: 'text-amber-600 bg-amber-50',
    },
];

const STATUSES = [
    {
        label: 'ใช้คำนวณแล้ว',
        text: 'ข้อมูลที่หน่วยงานนำเข้าและยืนยันความถูกต้องแล้ว ระบบนำไปคำนวณทันที',
        dot: 'bg-green-500',
    },
    {
        label: 'ไม่เกี่ยวข้อง (N/A)',
        text: 'รายการนั้นไม่เกี่ยวกับพื้นที่หรือภัยนั้น ระบบตัดออกและปรับน้ำหนักส่วนที่เหลือให้รวมเป็น 1',
        dot: 'bg-slate-300',
    },
    {
        label: 'ข้อมูลไม่ครบ',
        text: 'ชุมชนยังไม่มีข้อมูลจากบางหน่วยงาน ระบบจะยังไม่คำนวณผลของชุมชนนั้น และแสดงเป็นสีเทา',
        dot: 'bg-slate-500',
    },
];

const LIMITS = [
    'ผลการประเมินขึ้นอยู่กับความครบถ้วน ความเป็นปัจจุบัน และการตรวจสอบข้อมูลของหน่วยงานเจ้าของข้อมูล',
    'เกณฑ์และน้ำหนักบางส่วนเป็นเกณฑ์ปฏิบัติการของ MSK-Resilience รุ่นต้นแบบ และสามารถปรับเมื่อมีข้อมูลหลักฐานหรือมติของเทศบาลเพิ่มเติม',
    'การเปรียบเทียบข้ามเวลาใช้ได้เมื่อใช้เกณฑ์และ Version เดียวกัน หากมีการเปลี่ยนเกณฑ์ต้องแสดง Version กำกับ',
    'ข้อมูลกลุ่มเปราะบางที่แสดงต่อสาธารณะเป็นข้อมูลรวม ไม่ใช้เพื่อระบุตัวบุคคล',
];

const PAGES = [
    {
        route: 'risk-overview.index',
        label: 'ภาพรวมความเสี่ยง',
        text: 'เมืองเสี่ยงระดับใด ภัยใดเด่น และสถานการณ์เปลี่ยนอย่างไร',
        icon: 'fa-solid fa-chart-pie',
    },
    { route: 'community-map.index', label: 'แผนที่รายชุมชน', text: 'ความเสี่ยงของแต่ละชุมชนบนแผนที่ คลิกดูรายละเอียดได้', icon: 'fa-solid fa-map' },
    {
        route: 'risk-factors.index',
        label: 'ปัจจัยความเสี่ยง',
        text: 'ที่มาของคะแนน: ผู้ได้รับผลกระทบ ความเปราะบาง และความพร้อม',
        icon: 'fa-solid fa-triangle-exclamation',
    },
    {
        route: 'impact-recovery.index',
        label: 'ผลกระทบและการฟื้นตัว',
        text: 'ความเสียหายจริง การเงิน และการฟื้นฟูหลังเหตุ',
        icon: 'fa-solid fa-rotate',
    },
    { route: 'mcr2030-kpi.index', label: 'MCR2030 & KPI', text: 'ความสอดคล้องกับ MCR2030 และความก้าวหน้ารายหน่วยงาน', icon: 'fa-solid fa-bullseye' },
];

const card = 'h-full rounded-2xl border border-slate-100 bg-white p-6 shadow-sm';
</script>

<template>
    <Head title="เกี่ยวกับระบบ" />

    <AdminLayout title="เกี่ยวกับระบบและการอ่านข้อมูล">
        <!-- Header -->
        <!-- Background: the owner's banner (public/img/banner.png, served as the compressed banner.webp). It already
             contains the municipality outline at the top right, so it is anchored right and slightly above center to
             keep that outline in view on wide screens. A left-side gradient keeps the white text readable. -->
        <div class="relative overflow-hidden rounded-3xl bg-blue-900 p-8 text-white shadow-lg sm:p-10">
            <div
                class="banner-zoom absolute inset-0 bg-cover bg-[position:right_30%]"
                style="background-image: url('/img/banner.webp')"
                aria-hidden="true"
            ></div>
            <div class="absolute inset-0 bg-gradient-to-r from-blue-950/85 via-blue-900/55 to-transparent" aria-hidden="true"></div>
            <div class="relative max-w-2xl">
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-200">MSK-Resilience</p>
                <h1 class="mt-2 text-3xl font-bold leading-tight drop-shadow sm:text-4xl">ระบบข้อมูลเพื่อเมืองปลอดภัยและยืดหยุ่น</h1>
                <p class="mt-3 text-lg text-blue-100">ข้อมูลเพื่อการตัดสินใจ ลดความเสี่ยง ลดผลกระทบ สร้างเมืองที่พร้อมรับมืออนาคต</p>
                <div class="mt-6 flex flex-wrap gap-3 text-sm">
                    <span class="rounded-full bg-white/15 px-4 py-1.5 backdrop-blur">
                        <i class="fa-regular fa-calendar mr-1.5"></i>ข้อมูลล่าสุด {{ dataDate ? formatThaiDate(dataDate) : 'ยังไม่มีข้อมูล' }}
                    </span>
                    <span class="rounded-full bg-white/15 px-4 py-1.5 backdrop-blur"
                        ><i class="fa-solid fa-location-dot mr-1.5"></i>{{ communityCount }} ชุมชน</span
                    >
                    <span class="rounded-full bg-white/15 px-4 py-1.5 backdrop-blur">
                        <i class="fa-solid fa-building-columns mr-1.5"></i>เทศบาลเมืองมหาสารคาม
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <!-- What -->
            <Reveal class="lg:col-span-2">
                <div :class="card">
                    <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-800">
                        <i class="fa-solid fa-city text-blue-600"></i>ระบบนี้คืออะไร
                    </h2>
                    <p class="mt-3 leading-relaxed text-slate-600">
                        ระบบแสดงข้อมูลความเสี่ยงภัยพิบัติ ความพร้อมของพื้นที่ ผลกระทบ การฟื้นตัว และความก้าวหน้าในการดำเนินงานของหน่วยงาน
                        ระบบรวบรวมข้อมูลจาก 8 กลุ่มงานของเทศบาล ครอบคลุม {{ communityCount }} ชุมชน คำนวณเป็นคะแนนความเสี่ยงรายภัย 5 ประเภท ได้แก่
                        น้ำท่วม อัคคีภัย วาตภัย ความร้อน และฝุ่น PM2.5 เพื่อสนับสนุนการบริหารจัดการภัยพิบัติของเทศบาลเมืองมหาสารคาม
                    </p>
                </div>
            </Reveal>

            <!-- Data status -->
            <Reveal :delay="100">
                <div :class="card">
                    <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-800">
                        <i class="fa-solid fa-circle-check text-emerald-600"></i>สถานะข้อมูล
                    </h2>
                    <ul class="mt-3 space-y-3">
                        <li v-for="s in STATUSES" :key="s.label" class="flex gap-3">
                            <span class="mt-1.5 h-3 w-3 shrink-0 rounded-full" :class="s.dot"></span>
                            <div>
                                <p class="font-medium text-slate-700">{{ s.label }}</p>
                                <p class="text-xs leading-relaxed text-slate-500">{{ s.text }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </Reveal>
        </div>

        <!-- Terms -->
        <h2 class="mb-3 mt-8 text-lg font-semibold text-slate-800">คำสำคัญที่ใช้ในระบบ</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Reveal v-for="(t, i) in TERMS" :key="t.tech" :delay="i * 80">
                <div class="flex h-full gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-xl" :class="t.color"
                        ><i :class="t.icon"></i
                    ></span>
                    <div>
                        <p class="font-semibold text-slate-800">{{ t.label }}</p>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ t.tech }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ t.text }}</p>
                    </div>
                </div>
            </Reveal>
        </div>

        <!-- Reading scores -->
        <h2 class="mb-3 mt-8 text-lg font-semibold text-slate-800">วิธีอ่านคะแนน</h2>
        <div class="grid gap-6 lg:grid-cols-2">
            <Reveal>
                <div :class="card">
                    <h3 class="font-semibold text-slate-800">ระดับความเสี่ยง (0–100)</h3>
                    <p class="text-xs text-slate-400">ใช้กับคะแนนความเสี่ยงรายภัยและความเสี่ยงรวม (HEVC)</p>
                    <div class="mt-4 flex h-4 overflow-hidden rounded-full">
                        <div v-for="k in RISK_LEVEL_KEYS" :key="k" class="flex-1" :style="{ background: riskLevels[k].color }"></div>
                    </div>
                    <div class="mt-2 grid grid-cols-4 text-center text-xs">
                        <div v-for="k in RISK_LEVEL_KEYS" :key="k">
                            <p class="font-semibold text-slate-700">{{ riskLevels[k].label }}</p>
                            <p class="text-slate-400">{{ riskLevels[k].range }}</p>
                        </div>
                    </div>
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">
                        <i class="fa-solid fa-circle-info mr-1 text-blue-500"></i>คะแนนความเสี่ยงเป็นเครื่องมือเปรียบเทียบระดับความเสี่ยงระหว่างภัย
                        พื้นที่ และช่วงเวลา ไม่ใช่การพยากรณ์ว่าจะเกิดภัยเมื่อใด
                    </p>
                </div>
            </Reveal>
            <Reveal :delay="100">
                <div :class="card">
                    <h3 class="font-semibold text-slate-800">คะแนนย่อย (0–5)</h3>
                    <p class="text-xs text-slate-400">
                        ใช้กับผู้ได้รับผลกระทบ ความเปราะบาง ช่องว่าง และความพร้อมรับมือ · สีแดง = ต้องให้ความสำคัญเสมอ
                    </p>
                    <div class="mt-4 space-y-3 text-xs">
                        <div>
                            <p class="mb-1 font-medium text-slate-700">
                                ความเปราะบาง / ช่องว่าง / ผู้ได้รับผลกระทบ — <span class="font-normal">คะแนนสูง = แย่</span>
                            </p>
                            <div class="flex overflow-hidden rounded-lg">
                                <div
                                    v-for="(c, i) in SCORE_PALETTE"
                                    :key="i"
                                    class="flex-1 py-1 text-center font-semibold text-white"
                                    :style="{ background: c }"
                                >
                                    {{ i }}
                                </div>
                            </div>
                        </div>
                        <div>
                            <p class="mb-1 font-medium text-slate-700">
                                ความพร้อมรับมือ / การฟื้นฟู — <span class="font-normal">คะแนนสูง = ดี</span>
                            </p>
                            <div class="flex overflow-hidden rounded-lg">
                                <div
                                    v-for="(c, i) in [...SCORE_PALETTE].reverse()"
                                    :key="i"
                                    class="flex-1 py-1 text-center font-semibold text-white"
                                    :style="{ background: c }"
                                >
                                    {{ i }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">
                        <i class="fa-solid fa-circle-info mr-1 text-blue-500"></i>คะแนน 0 หมายถึงประเมินแล้วได้ 0 ไม่ใช่ไม่มีข้อมูล
                    </p>
                </div>
            </Reveal>
        </div>

        <!-- Limits -->
        <Reveal class="mt-8">
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="flex items-center gap-2 text-lg font-semibold text-amber-900">
                    <i class="fa-solid fa-triangle-exclamation"></i>ข้อควรทราบ
                </h2>
                <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm text-amber-900/90">
                    <li v-for="l in LIMITS" :key="l">{{ l }}</li>
                </ul>
                <p v-if="criteriaSets.length" class="mt-3 text-xs text-amber-800">
                    เกณฑ์คะแนนที่ใช้:
                    <span v-for="(s, i) in criteriaSets" :key="s.name">{{ i ? ', ' : '' }}{{ s.name }} (ตั้งแต่ปีงบ {{ s.start_fiscal_year }})</span>
                </p>
            </div>
        </Reveal>

        <!-- Shortcuts -->
        <h2 class="mb-3 mt-8 text-lg font-semibold text-slate-800">เริ่มใช้งาน</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <Reveal v-for="(p, i) in PAGES" :key="p.route" :delay="i * 70">
                <Link
                    :href="route(p.route)"
                    class="group flex h-full flex-col rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md"
                >
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600 transition-colors group-hover:bg-blue-600 group-hover:text-white"
                    >
                        <i :class="p.icon"></i>
                    </span>
                    <p class="mt-3 font-semibold text-slate-800">{{ p.label }}</p>
                    <p class="mt-1 flex-1 text-xs text-slate-500">{{ p.text }}</p>
                    <span class="mt-3 text-xs font-medium text-blue-600"
                        >เปิดหน้า <i class="fa-solid fa-arrow-right transition-transform group-hover:translate-x-1"></i
                    ></span>
                </Link>
            </Reveal>
        </div>

        <!-- Developer (spec §1 "หน่วยงานผู้พัฒนาระบบ"; content from the owner, 2026-11-10) -->
        <Reveal class="mt-8">
            <div class="flex flex-wrap items-center gap-5 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-slate-900 text-xl text-white">
                    <i class="fa-solid fa-flask"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">หน่วยงานผู้พัฒนาระบบ</p>
                    <p class="mt-1 font-semibold text-slate-800">หน่วยปฏิบัติการวิจัยการเปลี่ยนแปลงสภาพภูมิอากาศ การบรรเทา และการปรับตัว (CMARE)</p>
                    <p class="mt-0.5 text-sm text-slate-500">หัวหน้าโครงการ: ธายุกร พระบำรุง</p>
                    <p class="text-sm text-slate-500">ผู้พัฒนา: วงศ์นรินทร์ สุขวิชัย</p>
                </div>
            </div>
        </Reveal>
    </AdminLayout>
</template>

<style scoped>
/* The banner settles in with a slow, slight zoom-out (dashboard animation rule). */
.banner-zoom {
    animation: banner-settle 2.4s ease-out both;
}
@keyframes banner-settle {
    from {
        transform: scale(1.08);
        opacity: 0.4;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .banner-zoom {
        animation: none;
    }
}
</style>
