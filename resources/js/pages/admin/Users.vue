<script setup lang="ts">
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { SharedData, User, UserRole } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    users: User[];
    workGroups: { value: string; label: string; sheet: string }[];
}>();

const page = usePage<SharedData>();
const currentUserId = computed(() => page.props.auth.user.id);

const roleLabels: Record<UserRole, string> = {
    admin: 'ผู้ดูแลระบบ',
    staff: 'เจ้าหน้าที่',
    member: 'บุคคลทั่วไป',
};

const roleBadgeClass: Record<UserRole, string> = {
    admin: 'bg-blue-50 text-blue-600',
    staff: 'bg-emerald-50 text-emerald-600',
    member: 'bg-slate-100 text-slate-500',
};

const roleOptions: UserRole[] = ['admin', 'staff', 'member'];

const workGroupLabel = computed(() => Object.fromEntries(props.workGroups.map((g) => [g.value, g.label])));

// Search + role filter
const search = ref('');
const roleFilter = ref<UserRole | 'all'>('all');

const roleCounts = computed(() => {
    const counts: Record<UserRole, number> = { admin: 0, staff: 0, member: 0 };
    props.users.forEach((u) => counts[u.role]++);
    return counts;
});

const filteredUsers = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.users.filter(
        (u) =>
            (roleFilter.value === 'all' || u.role === roleFilter.value) &&
            (!q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)),
    );
});

// Modal state — clicking a row opens it in edit mode, where delete also lives.
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingUser = ref<User | null>(null);
const editingSelf = computed(() => editingUser.value?.id === currentUserId.value);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'member' as UserRole,
    work_groups: [] as string[],
});

function openCreateModal() {
    modalMode.value = 'create';
    editingUser.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEditModal(user: User) {
    modalMode.value = 'edit';
    editingUser.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.role = user.role;
    form.work_groups = [...(user.work_groups ?? [])];
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    if (modalMode.value === 'create') {
        form.post(route('users.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else if (editingUser.value) {
        form.put(route('users.update', editingUser.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
}

// Delete confirmation (opened from the edit modal, stacked on top of it). The server may still refuse
// (e.g. last admin) — that comes back as a red toast and the modal stays open.
const confirmDeleteOpen = ref(false);
const deleting = ref(false);

function destroyUser() {
    const user = editingUser.value;
    if (!user) return;
    deleting.value = true;
    router.delete(route('users.destroy', user.id), {
        preserveScroll: true,
        onSuccess: (visit) => {
            confirmDeleteOpen.value = false;
            if (!(visit.props as unknown as SharedData).flash?.error) closeModal();
        },
        onFinish: () => (deleting.value = false),
    });
}

function formatDate(value: string) {
    return new Date(value).toLocaleDateString('th-TH-u-ca-buddhist', { year: 'numeric', month: 'short', day: 'numeric' });
}

const inputClass =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="จัดการสมาชิก" />

    <AdminLayout title="จัดการสมาชิก">
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-semibold text-slate-800">จัดการสมาชิก</h2>
                    <p class="mt-0.5 text-sm text-slate-400">บัญชีผู้ใช้งานระบบทั้งหมด {{ users.length }} บัญชี · คลิกรายการเพื่อแก้ไขหรือลบ</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        ></i>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="ค้นหาชื่อหรืออีเมล"
                            class="w-56 rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-8 pr-3 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                    </div>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                    >
                        <i class="fa-solid fa-user-plus"></i>
                        เพิ่มผู้ใช้ใหม่
                    </button>
                </div>
            </div>

            <!-- Role filter -->
            <div class="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                    :class="roleFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                    @click="roleFilter = 'all'"
                >
                    ทั้งหมด {{ users.length }}
                </button>
                <button
                    v-for="role in roleOptions"
                    :key="role"
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                    :class="roleFilter === role ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                    @click="roleFilter = role"
                >
                    {{ roleLabels[role] }} {{ roleCounts[role] }}
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-medium">ชื่อ</th>
                            <th class="px-5 py-3 font-medium">อีเมล</th>
                            <th class="px-5 py-3 font-medium">สิทธิ์</th>
                            <th class="px-5 py-3 font-medium">กลุ่มงานที่นำเข้าได้</th>
                            <th class="px-5 py-3 font-medium">สร้างเมื่อ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="user in filteredUsers"
                            :key="user.id"
                            tabindex="0"
                            class="cursor-pointer hover:bg-blue-50/50 focus:bg-blue-50/50 focus:outline-none"
                            @click="openEditModal(user)"
                            @keydown.enter="openEditModal(user)"
                        >
                            <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-700">
                                {{ user.name }}
                                <span v-if="user.id === currentUserId" class="ml-1 text-xs font-normal text-slate-400">(คุณ)</span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ user.email }}</td>
                            <td class="whitespace-nowrap px-5 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="roleBadgeClass[user.role]">
                                    {{ roleLabels[user.role] }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span v-if="user.role === 'admin'" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-600">
                                    ทุกกลุ่มงาน
                                </span>
                                <div v-else-if="user.role === 'staff'" class="flex max-w-md flex-wrap gap-1">
                                    <span
                                        v-for="group in user.work_groups ?? []"
                                        :key="group"
                                        class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                    >
                                        {{ workGroupLabel[group] ?? group }}
                                    </span>
                                    <span v-if="!user.work_groups?.length" class="text-xs text-amber-600">ยังไม่ได้กำหนด</span>
                                </div>
                                <span v-else class="text-slate-300">–</span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-400">{{ formatDate(user.created_at) }}</td>
                        </tr>
                        <tr v-if="filteredUsers.length === 0">
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">ไม่พบผู้ใช้ที่ค้นหา</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="closeModal"
        >
            <div class="flex max-h-full w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between bg-blue-600 px-6 py-4">
                    <h3 class="text-base font-semibold text-white">
                        {{ modalMode === 'create' ? 'เพิ่มผู้ใช้ใหม่' : 'แก้ไขข้อมูลผู้ใช้' }}
                    </h3>
                    <button
                        type="button"
                        @click="closeModal"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-4 overflow-y-auto p-6">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ชื่อ-นามสกุล</label>
                        <input v-model="form.name" type="text" required :class="inputClass" />
                        <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">อีเมล</label>
                        <input v-model="form.email" type="email" required :class="inputClass" />
                        <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">
                            รหัสผ่าน{{ modalMode === 'edit' ? ' (เว้นว่างไว้หากไม่เปลี่ยน)' : '' }}
                        </label>
                        <input
                            v-model="form.password"
                            type="password"
                            :required="modalMode === 'create'"
                            :placeholder="modalMode === 'edit' ? '••••••••' : ''"
                            :class="inputClass"
                        />
                        <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">สิทธิ์การใช้งาน</label>
                        <select v-model="form.role" :class="inputClass">
                            <option v-for="opt in roleOptions" :key="opt" :value="opt">{{ roleLabels[opt] }}</option>
                        </select>
                        <p v-if="form.errors.role" class="mt-1.5 text-xs text-red-600">{{ form.errors.role }}</p>
                    </div>

                    <!-- Work groups: staff picks explicitly; admin is implicitly all; member has none -->
                    <div v-if="form.role === 'staff'">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">
                            กลุ่มงานที่นำเข้าข้อมูลได้ <span class="normal-case tracking-normal text-slate-400">(เลือกได้มากกว่า 1)</span>
                        </label>
                        <div
                            class="grid grid-cols-1 gap-1.5 rounded-xl border p-3 sm:grid-cols-2"
                            :class="form.errors.work_groups ? 'border-red-300 bg-red-50/40' : 'border-slate-200 bg-slate-50'"
                        >
                            <label
                                v-for="group in workGroups"
                                :key="group.value"
                                class="flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-white"
                            >
                                <input
                                    v-model="form.work_groups"
                                    type="checkbox"
                                    :value="group.value"
                                    class="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span>
                                    {{ group.label }}
                                    <span v-if="group.label !== group.sheet" class="block text-[11px] text-slate-400">ชีต {{ group.sheet }}</span>
                                </span>
                            </label>
                        </div>
                        <p v-if="form.errors.work_groups" class="mt-1.5 text-xs text-red-600">{{ form.errors.work_groups }}</p>
                    </div>
                    <p v-else-if="form.role === 'admin'" class="rounded-xl bg-blue-50 px-3.5 py-2.5 text-xs text-blue-700">
                        <i class="fa-solid fa-circle-info mr-1"></i>ผู้ดูแลระบบนำเข้าข้อมูลได้ทุกกลุ่มงานโดยอัตโนมัติ
                    </p>
                    <p v-else class="rounded-xl bg-slate-50 px-3.5 py-2.5 text-xs text-slate-500">
                        <i class="fa-solid fa-circle-info mr-1"></i>บุคคลทั่วไปดูแดชบอร์ดได้อย่างเดียว ไม่มีสิทธิ์นำเข้าข้อมูล
                    </p>

                    <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
                        <button
                            v-if="modalMode === 'edit' && !editingSelf"
                            type="button"
                            @click="confirmDeleteOpen = true"
                            class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                        >
                            <i class="fa-regular fa-trash-can"></i>
                            ลบผู้ใช้
                        </button>
                        <span v-else-if="modalMode === 'edit'" class="text-xs text-slate-400">ไม่สามารถลบบัญชีของตนเองได้</span>
                        <div class="ml-auto flex items-center gap-3">
                            <button
                                type="button"
                                @click="closeModal"
                                class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                            >
                                ยกเลิก
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {{ form.processing ? 'กำลังบันทึก...' : 'บันทึก' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmDeleteOpen"
            title="ยืนยันการลบผู้ใช้"
            :message="`ต้องการลบบัญชี “${editingUser?.name}” (${editingUser?.email}) ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`"
            confirm-label="ลบผู้ใช้"
            :processing="deleting"
            @confirm="destroyUser"
            @cancel="confirmDeleteOpen = false"
        />
    </AdminLayout>
</template>
