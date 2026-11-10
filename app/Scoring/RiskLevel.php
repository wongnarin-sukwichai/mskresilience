<?php

namespace App\Scoring;

/**
 * Risk bands for a 0–100 HEVC, spec v5 §1.3. The band is taken from the value rounded to 1 decimal, the precision
 * the dashboard shows (owner 2026-11-10), so a displayed 75.0 is never orange.
 */
enum RiskLevel: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case VeryHigh = 'very_high';

    public static function of(float $hevc): self
    {
        $value = round($hevc, 1);

        return match (true) {
            $value < 25 => self::Low,
            $value < 50 => self::Moderate,
            $value < 75 => self::High,
            default => self::VeryHigh,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'ต่ำ',
            self::Moderate => 'ปานกลาง',
            self::High => 'ค่อนข้างสูง',
            self::VeryHigh => 'สูง',
        };
    }
}
