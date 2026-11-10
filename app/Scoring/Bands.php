<?php

namespace App\Scoring;

/**
 * Raw value → 0–5 score, from spec v5 ภาคผนวก B ("เกณฑ์แปลงค่าดิบที่ใช้บ่อย"). Only the inputs staff enter as raw
 * numbers are converted here. Every other input is a 0–5 score entered as-is: the _SCORE columns, plus C5_3,
 * C8_3 and V_INFRA_GAP_* (owner 2026-11-06). Null in = null out (N/A).
 */
class Bands
{
    /**
     * E% and C6_3 (ร้อยละ): 0%=0, >0–20=1, >20–40=2, >40–60=3, >60–80=4, >80–100=5. Above 100 is treated as 5.
     */
    public static function percent(?float $pct): ?int
    {
        if ($pct === null) {
            return null;
        }

        return match (true) {
            $pct <= 0 => 0,
            $pct <= 20 => 1,
            $pct <= 40 => 2,
            $pct <= 60 => 3,
            $pct <= 80 => 4,
            default => 5,
        };
    }

    /**
     * A vulnerable group's share of POPULATION (V_*_COUNT ÷ POPULATION × 100):
     * 0%=0, >0–<5=1, 5–<10=2, 10–<20=3, 20–<30=4, ≥30=5.
     */
    public static function vulnerablePercent(?float $pct): ?int
    {
        if ($pct === null) {
            return null;
        }

        return match (true) {
            $pct <= 0 => 0,
            $pct < 5 => 1,
            $pct < 10 => 2,
            $pct < 20 => 3,
            $pct < 30 => 4,
            default => 5,
        };
    }

    /**
     * C4_1_VOLUNTEER_PER_1000: 0=0, >0–<4=1, 4–<6=2, 6–<8=3, 8–<10=4, ≥10=5.
     */
    public static function volunteersPer1000(?float $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $value <= 0 => 0,
            $value < 4 => 1,
            $value < 6 => 2,
            $value < 8 => 3,
            $value < 10 => 4,
            default => 5,
        };
    }

    /**
     * C4_2_TRAINING_COUNT (ครั้ง/ปี): 0=0, 1=2, 2=3, 3=4, ≥4=5.
     */
    public static function trainingCount(?int $count): ?int
    {
        if ($count === null) {
            return null;
        }

        return $count <= 0 ? 0 : min($count + 1, 5);
    }

    /**
     * C8_1_FORMAL_NETWORK_COUNT (จำนวนหน่วยงาน): 0=0 … 4=4, ≥5=5.
     */
    public static function networkCount(?int $count): ?int
    {
        return $count === null ? null : max(0, min($count, 5));
    }

    /**
     * C8_2_RESPONSE_HOURS: ≤1=5, >1–3=4, >3–6=3, >6–12=2, >12–24=1, >24=0.
     */
    public static function responseHours(?float $hours): ?int
    {
        if ($hours === null) {
            return null;
        }

        return match (true) {
            $hours <= 1 => 5,
            $hours <= 3 => 4,
            $hours <= 6 => 3,
            $hours <= 12 => 2,
            $hours <= 24 => 1,
            default => 0,
        };
    }
}
