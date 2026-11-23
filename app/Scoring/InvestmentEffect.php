<?php

namespace App\Scoring;

use App\Models\DisasterEvent;

/**
 * Before–after investment (TOR 4.1.2 "วิเคราะห์ก่อนและหลังการลงทุน", คุณลักษณะ 2.5-3, 2.6-3). Owner 2026-11-23: an
 * "investment point" is either
 * - an event with a risk-reduction budget (F4 > 0), or
 * - a round where the community's 3-year disaster budget average rose by at least BUDGET_RISE (10%).
 * Before = the community's last round with data before the investment; after = every later round with new data.
 * The result is a comparison of what was measured, not proof that the money caused the change.
 */
class InvestmentEffect
{
    public const BUDGET_RISE = 0.10;

    /**
     * @param  array{communities: array<string, array<int, array<string, mixed>>>}  $trend  RiskTrend::series()
     * @param  array<string, string>  $names  code → name
     * @return list<array<string, mixed>>
     */
    public static function points(array $trend, array $names): array
    {
        $out = [];

        foreach (DisasterEvent::with('community:id,code')->where('f4_event_risk_reduction_budget_thb', '>', 0)->get() as $event) {
            $code = $event->community->code;
            $start = $event->event_start->toDateString();
            $series = $trend['communities'][$code] ?? [];
            $before = self::lastBefore($series, $start);
            $after = $before ? self::after($series, $before['year'], $start) : [];
            if ($before && $after) {
                $out[] = self::row('f4', $code, $names[$code] ?? $code, $start, (float) $event->f4_event_risk_reduction_budget_thb,
                    "งบลดความเสี่ยง (F4) จากเหตุการณ์ {$event->event_id}", $before, $after);
            }
        }

        foreach ($trend['communities'] as $code => $series) {
            ksort($series);
            $previous = null;
            foreach ($series as $year => $point) {
                if ($point['stale'] || $point['budget'] === null) {
                    continue;
                }
                if ($previous && $previous['budget'] > 0 && $point['budget'] >= $previous['budget'] * (1 + self::BUDGET_RISE)) {
                    $after = self::after($series, $previous['year'], $previous['dataDate']);
                    if ($after) {
                        $out[] = self::row('budget', $code, $names[$code] ?? $code, $point['dataDate'], $point['budget'] - $previous['budget'],
                            sprintf('งบด้านภัยเฉลี่ย 3 ปี เพิ่มจาก %s เป็น %s บาท', number_format($previous['budget']), number_format($point['budget'])), $previous, $after);
                    }
                }
                $previous = ['year' => $year] + $point;
            }
        }

        usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $before  a trend point with `year`
     * @param  list<array<string, mixed>>  $after
     * @return array<string, mixed>
     */
    private static function row(string $type, string $code, string $name, string $date, float $amount, string $label, array $before, array $after): array
    {
        $last = end($after);
        $hazard = $before['dominant'];
        $parts = fn ($p) => $hazard ? ($p['parts'][$hazard] ?? null) : null;
        $b = $parts($before);
        $a = $parts($last);

        return [
            'type' => $type,
            'code' => $code,
            'name' => $name,
            'date' => $date,
            'amount' => $amount,
            'label' => $label,
            'before' => ['year' => $before['year'], 'multi' => $before['multi'], 'level' => $before['level']],
            'after' => array_map(fn ($p) => ['year' => $p['year'], 'multi' => $p['multi'], 'level' => $p['level']], $after),
            'delta' => $last['multi'] !== null && $before['multi'] !== null ? $last['multi'] - $before['multi'] : null,
            // E/V/C of the hazard that was dominant before, first vs last: which part moved.
            'hazard' => $hazard,
            'parts' => $b && $a ? [
                'e' => $a['e'] !== null && $b['e'] !== null ? $a['e'] - $b['e'] : null,
                'v' => $a['v'] !== null && $b['v'] !== null ? $a['v'] - $b['v'] : null,
                'cRaw' => $a['cRaw'] !== null && $b['cRaw'] !== null ? $a['cRaw'] - $b['cRaw'] : null,
            ] : null,
        ];
    }

    /**
     * The latest round whose data is dated before $date, with `year`.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>|null
     */
    private static function lastBefore(array $series, string $date): ?array
    {
        krsort($series);
        foreach ($series as $year => $p) {
            if ($p['dataDate'] !== null && $p['dataDate'] < $date && $p['multi'] !== null) {
                return ['year' => $year] + $p;
            }
        }

        return null;
    }

    /**
     * Rounds after $year with new data dated after $date, oldest first.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    private static function after(array $series, int $year, ?string $date): array
    {
        ksort($series);
        $out = [];
        foreach ($series as $y => $p) {
            if ($y > $year && ! $p['stale'] && $p['multi'] !== null && ($date === null || $p['dataDate'] > $date)) {
                $out[] = ['year' => $y] + $p;
            }
        }

        return $out;
    }
}
