<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    /**
     * List all user accounts.
     */
    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Create a new user account. Self-registration is disabled system-wide (see routes/auth.php),
     * so this is the only way new accounts get created.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
            'role' => $request->validated('role'),
            'email_verified_at' => now(),
        ]);

        return back()->with('status', 'สร้างบัญชีผู้ใช้เรียบร้อยแล้ว');
    }

    /**
     * Update an existing user account.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->guardLastAdmin($user, UserRole::from($request->validated('role')));

        $user->name = $request->validated('name');
        $user->email = $request->validated('email');
        $user->role = $request->validated('role');

        if ($password = $request->validated('password')) {
            $user->password = Hash::make($password);
        }

        $user->save();

        return back()->with('status', 'บันทึกข้อมูลผู้ใช้เรียบร้อยแล้ว');
    }

    /**
     * Delete a user account.
     */
    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->is(Auth::user()), 403, 'ไม่สามารถลบบัญชีของตนเองได้');

        $this->guardLastAdmin($user, null);

        $user->delete();

        return back()->with('status', 'ลบบัญชีผู้ใช้เรียบร้อยแล้ว');
    }

    /**
     * Prevent the system's last admin account from being demoted or deleted, which would
     * lock every admin feature (including this one) behind an account nobody can use.
     * $newRole is null for a delete, or the role the account is being changed to for an update.
     */
    private function guardLastAdmin(User $user, ?UserRole $newRole): void
    {
        $losingAdminRole = $user->role === UserRole::Admin && $newRole !== UserRole::Admin;

        if (! $losingAdminRole) {
            return;
        }

        $remainingAdmins = User::where('role', UserRole::Admin)->where('id', '!=', $user->id)->count();

        abort_if($remainingAdmins === 0, 403, 'ต้องมีผู้ดูแลระบบ (admin) อย่างน้อย 1 บัญชีในระบบเสมอ');
    }
}
