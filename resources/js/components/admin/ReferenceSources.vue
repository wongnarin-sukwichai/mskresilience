<script setup lang="ts">
// Page 1's "แหล่งข้อมูลและแนวทางการใช้งาน" section (owner's public/ref/ref.docx, 2026-11-26). Reference reading
// only: none of these external sources feed the system's calculations, and the section says so.
import Reveal from '@/components/admin/Reveal.vue';
import { REF_CATEGORIES, REF_CAUTIONS, REF_HORIZON_NOTE, REF_HORIZONS, REF_INTRO, REF_SKILLS } from '@/data/referenceSources';
import { computed, ref } from 'vue';

const activeKey = ref(REF_CATEGORIES[0].key);
const active = computed(() => REF_CATEGORIES.find((c) => c.key === activeKey.value) ?? REF_CATEGORIES[0]);
</script>

<template>
    <section class="mt-10">
        <Reveal>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-800">
                        <i class="fa-solid fa-book-open text-blue-600"></i>แหล่งข้อมูลและแนวทางการใช้งาน
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm leading-relaxed text-slate-600">{{ REF_INTRO }}</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-500">
                    <i class="fa-solid fa-circle-info mr-1 text-blue-500"></i>ข้อมูลอ้างอิงภายนอก ไม่ได้นำมาคำนวณในระบบ
                </span>
            </div>
        </Reveal>

        <!-- 1. Sources by category -->
        <Reveal class="mt-5">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
                <h3 class="font-semibold text-slate-800">1. แหล่งข้อมูลที่สำคัญและจำเป็น</h3>
                <div class="mt-4 flex flex-wrap gap-2" role="tablist">
                    <button
                        v-for="c in REF_CATEGORIES"
                        :key="c.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeKey === c.key"
                        class="flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm transition-colors"
                        :class="
                            activeKey === c.key
                                ? 'border-blue-600 bg-blue-600 text-white shadow-sm'
                                : 'border-slate-200 bg-white text-slate-600 hover:border-blue-300 hover:text-blue-700'
                        "
                        @click="activeKey = c.key"
                    >
                        <i :class="c.icon"></i>{{ c.label }}
                        <span class="rounded-full px-1.5 text-[11px]" :class="activeKey === c.key ? 'bg-white/20' : 'bg-slate-100'">{{
                            c.sources.length
                        }}</span>
                    </button>
                </div>

                <TransitionGroup name="ref-card" tag="div" class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="(s, i) in active.sources"
                        :key="`${active.key}-${i}`"
                        class="flex flex-col rounded-xl border border-slate-100 bg-slate-50/60 p-4"
                        :style="{ transitionDelay: `${i * 40}ms` }"
                    >
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="active.color">
                                <i :class="active.icon"></i>
                            </span>
                            <p class="min-w-0 font-semibold leading-snug text-slate-800">
                                <template v-for="(n, j) in s.names" :key="n.label">
                                    <span v-if="j" class="font-normal text-slate-400"> / </span>
                                    <a
                                        v-if="n.url"
                                        :href="n.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-blue-700 underline-offset-2 hover:underline"
                                        >{{ n.label }}<i class="fa-solid fa-arrow-up-right-from-square ml-1 text-[10px] text-blue-400"></i
                                    ></a>
                                    <span v-else>{{ n.label }}</span>
                                </template>
                            </p>
                        </div>
                        <p class="mt-3 text-sm text-slate-600"><span class="font-medium text-slate-700">การนำไปใช้:</span> {{ s.use }}</p>
                        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                            <i class="fa-solid fa-triangle-exclamation mr-1 text-amber-500"></i>ข้อจำกัด: {{ s.limit }}
                        </p>
                    </article>
                </TransitionGroup>
            </div>
        </Reveal>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <!-- 2. Time horizons -->
            <Reveal>
                <div class="h-full rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
                    <h3 class="font-semibold text-slate-800">2. เลือกใช้ข้อมูลให้เหมาะกับช่วงเวลา</h3>
                    <ol class="relative mt-4 space-y-4 border-l-2 border-blue-100 pl-6">
                        <li v-for="h in REF_HORIZONS" :key="h.when" class="relative">
                            <span
                                class="absolute -left-[37px] flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-[11px] text-white ring-4 ring-white"
                            >
                                <i :class="h.icon"></i>
                            </span>
                            <p class="text-sm font-semibold text-slate-800">{{ h.when }}</p>
                            <p class="text-sm text-slate-600">{{ h.use }}</p>
                        </li>
                    </ol>
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">
                        <i class="fa-solid fa-circle-info mr-1 text-blue-500"></i>{{ REF_HORIZON_NOTE }}
                    </p>
                </div>
            </Reveal>

            <!-- 3. Skills -->
            <Reveal :delay="100">
                <div class="h-full rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
                    <h3 class="font-semibold text-slate-800">3. พัฒนาทักษะก่อนใช้ข้อมูล</h3>
                    <p class="text-xs text-slate-400">ผู้ใช้ควรพัฒนาความรู้และทักษะพื้นฐาน 6 ประการ</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div v-for="(s, i) in REF_SKILLS" :key="s.title" class="flex gap-3 rounded-xl border border-slate-100 p-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                <i :class="s.icon"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ i + 1 }}. {{ s.title }}</p>
                                <p class="text-xs leading-relaxed text-slate-500">{{ s.text }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </Reveal>
        </div>

        <!-- 4. Cautions -->
        <Reveal class="mt-6">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
                <h3 class="font-semibold text-slate-800">4. เงื่อนไขและข้อควรระวังในการใช้ข้อมูล</h3>
                <ul class="mt-4 grid gap-2 md:grid-cols-2">
                    <li
                        v-for="c in REF_CAUTIONS"
                        :key="c.text"
                        class="flex gap-2.5 rounded-lg px-3 py-2 text-sm"
                        :class="c.urgent ? 'bg-red-50 font-medium text-red-800 md:col-span-2' : 'text-slate-600'"
                    >
                        <i
                            class="mt-1 shrink-0 text-xs"
                            :class="c.urgent ? 'fa-solid fa-circle-exclamation text-red-500' : 'fa-solid fa-check text-blue-500'"
                        ></i>
                        <span>{{ c.text }}</span>
                    </li>
                </ul>
            </div>
        </Reveal>
    </section>
</template>

<style scoped>
.ref-card-enter-active {
    transition:
        opacity 0.3s ease,
        transform 0.3s ease;
}
.ref-card-enter-from {
    opacity: 0;
    transform: translateY(8px);
}
.ref-card-leave-active {
    display: none;
}
@media (prefers-reduced-motion: reduce) {
    .ref-card-enter-active {
        transition: none;
    }
}
</style>
