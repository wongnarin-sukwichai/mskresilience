<?php

namespace App\Import\Sheets;

use App\Models\DisasterRecord;

/**
 * งานป้องกันภัย (sheet "งาน ปภ1"): one row per community per ASSESSMENT_DATE, into disaster_records.
 *
 * Rules follow the client's คำอธิบาย sheet (ColumnDescriptions). Whole counts are COUNT, the "N/A, 0–5" items
 * are SCORE, and measured values (km², rate per 1,000, hours, baht) are DECIMAL: ≥ 0 with 2 decimal places
 * (owner, 2026-11-03). C6_3_READINESS_SCORE is PERCENT despite its name, because the client defines it as ร้อยละ
 * and their sample has 20–100.
 */
class DisasterSheet extends CommunityDateSheet
{
    protected function model(): string
    {
        return DisasterRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'TAMBON' => self::TEXT,
            'AMPHOE' => self::TEXT,
            'PROVINCE' => self::TEXT,
            'POPULATION' => self::COUNT,
            'HOUSEHOLDS' => self::COUNT,
            'AREA_KM2' => self::DECIMAL,
            'E_NUMERATOR_COUNT_FLOOD' => self::COUNT,
            'E_NUMERATOR_COUNT_FIRE' => self::COUNT,
            'E_NUMERATOR_COUNT_STORM' => self::COUNT,
            'E_NUMERATOR_COUNT_HEAT' => self::COUNT,
            'E_NUMERATOR_COUNT_PM25' => self::COUNT,
            'C4_1_VOLUNTEER_PER_1000' => self::DECIMAL,
            'C4_2_TRAINING_COUNT' => self::COUNT,
            'C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE' => self::SCORE,
            'C6_2_LIFESAVING_SCORE' => self::SCORE,
            'C6_3_READINESS_SCORE' => self::PERCENT,
            'C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE' => self::SCORE,
            'C7_1_FLOOD_SCORE' => self::SCORE,
            'C7_1_FIRE_SCORE' => self::SCORE,
            'C7_1_STORM_SCORE' => self::SCORE,
            'C7_2_SAFE_PLACE_SCORE' => self::SCORE,
            'C7_3_DRILL_SCORE' => self::SCORE,
            'C8_1_FORMAL_NETWORK_COUNT' => self::COUNT,
            'C8_2_RESPONSE_HOURS' => self::DECIMAL,
            'C8_3_NETWORK_TYPES_SCORE' => self::SCORE,
            'C8_4_JOINT_EXPERIENCE_SCORE' => self::SCORE,
            'R1_RECOVERY_PLAN_SCORE' => self::SCORE,
            'R2_BUILD_BACK_BETTER_SCORE' => self::SCORE,
            'R3_SOCIOECONOMIC_RECOV_SCORE' => self::SCORE,
            'R4_VULNER_GROUP_RECOV_SCORE' => self::SCORE,
            'R5_LEARNING_SYS_IMPROV_SCORE' => self::SCORE,
            'M_BUDGET_AVG_3Y' => self::DECIMAL,
        ];
    }
}
