<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
            'workGroups' => WorkGroup::options(),
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
            'work_groups' => $this->workGroupsFor($request->validated('role'), $request->validated('work_groups')),
            'email_verified_at' => now(),
        ]);

        return back()->with('status', 'สร้างบัญชีผู้ใช้เรียบร้อยแล้ว');
    }

    /**
     * Update an existing user account.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($message = $this->lastAdminViolation($user, UserRole::from($request->validated('role')))) {
            throw ValidationException::withMessages(['role' => $message]);
        }

        $user->name = $request->validated('name');
        $user->email = $request->validated('email');
        $user->role = $request->validated('role');
        $user->work_groups = $this->workGroupsFor($request->validated('role'), $request->validated('work_groups'));

        if ($password = $request->validated('password')) {
            $user->password = Hash::make($password);
        }

        $user->save();

        return back()->with('status', 'บันทึกข้อมูลผู้ใช้เรียบร้อยแล้ว');
    }

    /**
     * Delete a user account. Guard failures come back as an error toast (flash 'error'), not a 403 page.
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->is(Auth::user())) {
            return back()->with('error', 'ไม่สามารถลบบัญชีของตนเองได้');
        }

        if ($message = $this->lastAdminViolation($user, null)) {
            return back()->with('error', $message);
        }

        $user->delete();

        return back()->with('status', 'ลบบัญชีผู้ใช้เรียบร้อยแล้ว');
    }

    /**
     * Only staff store explicit groups: admin imports everything implicitly and member imports nothing
     * (see User::canImport()), so their lists are always saved empty rather than kept stale.
     *
     * @param  list<string>|null  $groups
     * @return list<string>
     */
    private function workGroupsFor(string $role, ?array $groups): array
    {
        return $role === UserRole::Staff->value ? array_values(array_unique($groups ?? [])) : [];
    }

    /**
     * Prevent the system's last admin account from being demoted or deleted, which would
     * lock every admin feature (including this one) behind an account nobody can use.
     * $newRole is null for a delete, or the role the account is being changed to for an update.
     * Returns the Thai error message when the action would violate this, null otherwise.
     */
    private function lastAdminViolation(User $user, ?UserRole $newRole): ?string
    {
        $losingAdminRole = $user->role === UserRole::Admin && $newRole !== UserRole::Admin;

        if (! $losingAdminRole) {
            return null;
        }

        $remainingAdmins = User::where('role', UserRole::Admin)->where('id', '!=', $user->id)->count();

        return $remainingAdmins === 0 ? 'ต้องมีผู้ดูแลระบบ (admin) อย่างน้อย 1 บัญชีในระบบเสมอ' : null;
    }
}
