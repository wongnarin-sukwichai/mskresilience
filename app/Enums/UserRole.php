<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'ผู้ดูแลระบบ',
            self::Staff => 'เจ้าหน้าที่',
            self::Member => 'บุคคลทั่วไป',
        };
    }
}
