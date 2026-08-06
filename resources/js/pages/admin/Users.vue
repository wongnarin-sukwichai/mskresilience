<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { User, UserRole } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    users: User[];
}>();

const roleLabels: Record<UserRole, string> = {
    admin: 'ผู้ดูแลระบบ',
    staff: 'เจ้าหน้าที่',
    member: 'สมาชิก',
};

const roleBadgeClass: Record<UserRole, string> = {
    admin: 'bg-blue-50 text-blue-600',
    staff: 'bg-emerald-50 text-emerald-600',
    member: 'bg-slate-100 text-slate-500',
};

const roleOptions: UserRole[] = ['admin', 'staff', 'member'];

// Modal state
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingUserId = ref<number | null>(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'member' as UserRole,
});

function openCreateModal() {
    modalMode.value = 'create';
    editingUserId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEditModal(user: User) {
    modalMode.value = 'edit';
    editingUserId.value = user.id;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.role = user.role;
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
    } else if (editingUserId.value !== null) {
        form.put(route('users.update', editingUserId.value), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
}

function destroyUser(user: User) {
    if (!confirm(`ยืนยันลบบัญชี "${user.name}" ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`)) return;
    router.delete(route('users.destroy', user.id), { preserveScroll: true });
}

function formatDate(value: string) {
    return new Date(value).toLocaleDateString('th-TH-u-ca-buddhist', { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <Head title="จัดการสมาชิก" />

    <AdminLayout title="จัดการสมาชิก">
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-semibold text-slate-800">จัดการสมาชิก</h2>
                    <p class="mt-0.5 text-sm text-slate-400">บัญชีผู้ใช้งานระบบทั้งหมด {{ users.length }} บัญชี</p>
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

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs tracking-wider text-slate-400 uppercase">
                        <tr>
                            <th class="px-5 py-3 font-medium">ชื่อ</th>
                            <th class="px-5 py-3 font-medium">อีเมล</th>
                            <th class="px-5 py-3 font-medium">สิทธิ์</th>
                            <th class="px-5 py-3 font-medium">สร้างเมื่อ</th>
                            <th class="px-5 py-3 font-medium">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="user in users" :key="user.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-medium whitespace-nowrap text-slate-700">{{ user.name }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-slate-500">{{ user.email }}</td>
                            <td class="px-5 py-3 whitespace-nowrap">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="roleBadgeClass[user.role]">
                                    {{ roleLabels[user.role] }}
                                </span>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-slate-400">{{ formatDate(user.created_at) }}</td>
                            <td class="px-5 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-3 text-slate-400">
                                    <button type="button" title="แก้ไข" @click="openEditModal(user)" class="hover:text-blue-600">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </button>
                                    <button type="button" title="ลบ" @click="destroyUser(user)" class="hover:text-red-600">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
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
            <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ modalMode === 'create' ? 'เพิ่มผู้ใช้ใหม่' : 'แก้ไขข้อมูลผู้ใช้' }}
                    </h3>
                    <button type="button" @click="closeModal" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase">ชื่อ-นามสกุล</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                        />
                        <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase">อีเมล</label>
                        <input
                            v-model="form.email"
                            type="email"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                        />
                        <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase">
                            รหัสผ่าน{{ modalMode === 'edit' ? ' (เว้นว่างไว้หากไม่เปลี่ยน)' : '' }}
                        </label>
                        <input
                            v-model="form.password"
                            type="password"
                            :required="modalMode === 'create'"
                            :placeholder="modalMode === 'edit' ? '••••••••' : ''"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                        />
                        <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold tracking-wider text-slate-500 uppercase">สิทธิ์การใช้งาน</label>
                        <select
                            v-model="form.role"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 focus:outline-none"
                        >
                            <option v-for="opt in roleOptions" :key="opt" :value="opt">{{ roleLabels[opt] }}</option>
                        </select>
                        <p v-if="form.errors.role" class="mt-1.5 text-xs text-red-600">{{ form.errors.role }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800">
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
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
