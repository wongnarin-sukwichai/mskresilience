<?php

namespace Database\Seeders;

use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSchema;
use Illuminate\Database\Seeder;

class ScoringCriteriaSeeder extends Seeder
{
    /**
     * Seed the first criteria set from the client's ค่าถ่วงน้ำหนัก.docx — active but unlocked, so the admin can
     * review the numbers before locking. Idempotent: does nothing if any set already exists.
     */
    public function run(): void
    {
        if (ScoringCriteriaSet::exists()) {
            return;
        }

        ScoringCriteriaSet::create([
            'name' => 'เกณฑ์เริ่มต้น (ตามไฟล์ค่าถ่วงน้ำหนัก)',
            'is_active' => true,
            'is_locked' => false,
            'weights' => CriteriaSchema::defaults(),
        ]);
    }
}
