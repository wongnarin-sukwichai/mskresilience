<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

// ตั้งค่าทั่วไป (ตั้งค่าระบบ module, admin, SystemSettingsController). The list of settings, their groups, labels,
// limits and defaults come from App\Support\Settings; this page only renders them. Every change goes to บันทึกการแก้ไข.
interface Setting {
    key: string;
    group: string;
    type: 'bool' | 'text' | 'number' | 'select';
    label: string;
    help: string;
    value: boolean | string | number;
    default: boolean | string | number;
    min?: number;
    max?: number;
    step?: number;
    unit?: string;
    options?: Record<string, string>;
}

const props = defineProps<{ settings: Setting[] }>();

const form = useForm<Record<string, boolean | string | number>>(Object.fromEntries(props.settings.map((s) => [s.key, s.value])));
const groups = computed(() =>
    [...new Set(props.settings.map((s) => s.group))].map((g) => ({ name: g, items: props.settings.filter((s) => s.group === g) })),
);

// The fixed thresholds only apply in "fixed" mode and the percentage only in "relative" mode: dim the other ones.
const inactive = (s: Setting) =>
    (form.flag_mode === 'relative' && ['flag_hevc', 'flag_budget_per_person', 'flag_recovery'].includes(s.key)) ||
    (form.flag_mode === 'fixed' && s.key === 'flag_percent');

function save() {
    // The saved values become the new baseline, so "ยังไม่บันทึก" goes away.
    form.put(route('system-settings.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
}

const inputClass =
    'rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="ตั้งค่าทั่วไป" />

    <AdminLayout title="ตั้งค่าทั่วไป">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">ตั้งค่าทั่วไป</h1>
            <p class="mt-1 text-sm text-slate-500">สวิตช์และเกณฑ์การทำงานของระบบ ทุกการเปลี่ยนแปลงถูกบันทึกในบันทึกการแก้ไข</p>
        </div>

        <form class="max-w-3xl space-y-5" @submit.prevent="save">
            <section v-for="g in groups" :key="g.name" class="rounded-2xl border border-slate-100 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-6 py-3 text-sm font-semibold text-slate-700">{{ g.name }}</h2>
                <div
                    v-for="s in g.items"
                    :key="s.key"
                    class="flex items-start gap-4 border-b border-slate-100 px-6 py-5 transition-opacity last:border-0"
                    :class="{ 'opacity-40': inactive(s) }"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-800">{{ s.label }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-500">{{ s.help }}</p>
                        <textarea
                            v-if="s.type === 'text'"
                            v-model="form[s.key] as string"
                            rows="2"
                            maxlength="500"
                            :class="[inputClass, 'mt-3 w-full']"
                        ></textarea>
                        <select v-else-if="s.type === 'select'" v-model="form[s.key]" :class="[inputClass, 'mt-3 w-full sm:w-auto']">
                            <option v-for="(label, value) in s.options" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <p v-if="form.errors[s.key]" class="mt-1 text-xs text-red-600">{{ form.errors[s.key] }}</p>
                    </div>
                    <div v-if="s.type === 'number'" class="flex shrink-0 items-center gap-2">
                        <input
                            v-model.number="form[s.key]"
                            type="number"
                            :min="s.min"
                            :max="s.max"
                            :step="s.step"
                            :class="[inputClass, 'w-28 text-right']"
                        />
                        <span class="w-14 text-xs text-slate-500">{{ s.unit }}</span>
                    </div>
                    <button
                        v-if="s.type === 'bool'"
                        type="button"
                        role="switch"
                        :aria-checked="!!form[s.key]"
                        :aria-label="s.label"
                        class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full transition-colors"
                        :class="form[s.key] ? 'bg-blue-600' : 'bg-slate-300'"
                        @click="form[s.key] = !form[s.key]"
                    >
                        <span
                            class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                            :class="form[s.key] ? 'translate-x-5' : 'translate-x-0'"
                        ></span>
                    </button>
                </div>
            </section>

            <div class="sticky bottom-4 flex items-center justify-end gap-3 rounded-2xl border border-slate-100 bg-white px-6 py-4 shadow-lg">
                <span v-if="form.isDirty" class="text-xs text-amber-600">มีการเปลี่ยนแปลงที่ยังไม่บันทึก</span>
                <button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <i class="fa-solid fa-floppy-disk mr-1.5"></i>บันทึก
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
