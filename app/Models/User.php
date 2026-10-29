<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\WorkGroup;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'work_groups',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'work_groups' => AsEnumCollection::of(WorkGroup::class),
        ];
    }

    /**
     * Whether this user may import the given กลุ่มงาน's sheet. Admin: every group, implicitly (nothing to tick).
     * Staff: only the groups an admin granted. Member: none. Single source of truth for the future import
     * menu's per-sheet check — don't re-derive it there.
     */
    public function canImport(WorkGroup $group): bool
    {
        return match ($this->role) {
            UserRole::Admin => true,
            UserRole::Staff => (bool) $this->work_groups?->contains($group),
            UserRole::Member => false,
        };
    }

    /**
     * Whether this user's role is allowed into the /dashboard admin panel.
     * Single source of truth for both the post-login redirect and the
     * "already authenticated" guest-redirect (see AppServiceProvider) —
     * those two must never fall out of sync with each other or with the
     * `role:admin,staff,member` middleware on the dashboard route.
     */
    public function canAccessAdminPanel(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Staff, UserRole::Member], true);
    }
}
