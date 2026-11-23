<?php

namespace App\Scoring;

use App\Support\Settings;

/**
 * Policy Flags for the executive page (TOR 4.1.1(3)-3, 4.1.2 "แจ้งเตือนชุมชนเสี่ยงสูงต่อเนื่อง", คุณลักษณะ 2.4-3,
 * 2.6-2). Thresholds are admin settings (ตั้งค่าทั่วไป, owner 2026-11-23 option ค):
 * - relative (default): "high" = in the top N% of the city, "low" = in the bottom N%, so there is always something
 *   to watch even when the whole city is low risk;
 * - fixed: the absolute values in the settings.
 * Continuous-high and rising always use real levels/points, never the relative cut. Every flag carries the numbers
 * that triggered it, so it can be checked.
 */
class PolicyFlags
{
    public const TYPES = [
        'high_risk_low_budget' => ['label' => 'เสี่ยงสูง แต่งบประมาณต่ำ', 'icon' => 'fa-solid fa-sack-xmark', 'tor' => '4.1.1(3)-3'],
        'high_risk_low_recovery' => ['label' => 'เสี่ยงสูง และความพร้อมฟื้นฟูต่ำ', 'icon' => 'fa-solid fa-person-falling-burst', 'tor' => '4.1.1(3)-3'],
        'continuous_high' => ['label' => 'เสี่ยงสูงต่อเนื่อง', 'icon' => 'fa-solid fa-bell', 'tor' => 'คุณลักษณะ 2.6-2'],
        'rising' => ['label' => 'ความเสี่ยงเพิ่มขึ้นมาก', 'icon' => 'fa-solid fa-arrow-trend-up', 'tor' => '4.1.2'],
    ];

    /**
     * @param  array{communities: list<array<string, mixed>>}  $result  the latest RiskEngine run
     * @param  array{years: list<int>, communities: array<string, array<int, array<string, mixed>>>}  $trend  RiskTrend::series()
     * @return array{thresholds: array<string, mixed>, flags: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function evaluate(array $result, array $trend): array
    {
        $scored = array_values(array_filter($result['communities'], fn ($c) => $c['status'] === 'ok' && ($c['multi']['hevc'] ?? null) !== null));
        $perPerson = fn ($c) => isset($c['inputs']['m_budget_avg_3y']) && $c['population'] ? (float) $c['inputs']['m_budget_avg_3y'] / $c['population'] : null;

        $mode = Settings::get('flag_mode');
        $percent = (float) Settings::get('flag_percent');
        if ($mode === 'relative') {
            $hevcCut = self::cut(array_map(fn ($c) => $c['multi']['hevc'], $scored), $percent, top: true);
            $budgetCut = self::cut(array_map($perPerson, $scored), $percent, top: false);
            $recoveryCut = self::cut(array_map(fn ($c) => $c['recovery'], $scored), $percent, top: false);
            $how = "อยู่ใน {$percent}% ";
        } else {
            $hevcCut = (float) Settings::get('flag_hevc');
            $budgetCut = (float) Settings::get('flag_budget_per_person');
            $recoveryCut = (float) Settings::get('flag_recovery');
            $how = '';
        }
        $years = (int) Settings::get('flag_consecutive_years');
        $rise = (float) Settings::get('flag_rise');

        $flags = [];
        $add = function (string $type, array $c, string $reason, float $sort) use (&$flags) {
            $flags[] = ['type' => $type, 'code' => $c['code'], 'name' => $c['name'], 'reason' => $reason, 'sort' => $sort] + self::TYPES[$type];
        };
        foreach ($scored as $c) {
            $hevc = $c['multi']['hevc'];
            $high = $hevcCut !== null && $hevc >= $hevcCut;
            $budget = $perPerson($c);
            if ($high && $budget !== null && $budgetCut !== null && $budget <= $budgetCut) {
                $add('high_risk_low_budget', $c, sprintf('HEVC %.1f (%sเกณฑ์ ≥ %.1f) · งบด้านภัย %s บาท/คน (%sเกณฑ์ ≤ %s)',
                    $hevc, $how ? $how.'บนสุด ' : '', $hevcCut, number_format($budget), $how ? $how.'ล่างสุด ' : '', number_format($budgetCut)), $hevc);
            }
            if ($high && $c['recovery'] !== null && $recoveryCut !== null && $c['recovery'] <= $recoveryCut) {
                $add('high_risk_low_recovery', $c, sprintf('HEVC %.1f (เกณฑ์ ≥ %.1f) · ความพร้อมฟื้นฟู %.1f/5 (เกณฑ์ ≤ %.1f)', $hevc, $hevcCut, $c['recovery'], $recoveryCut), $hevc);
            }

            $series = $trend['communities'][$c['code']] ?? [];
            $run = RiskTrend::consecutiveHigh($series);
            if ($run >= $years) {
                $add('continuous_high', $c, sprintf('ระดับค่อนข้างสูงขึ้นไปติดกัน %d ปีงบ (ล่าสุด HEVC %.1f)', $run, $hevc), $run * 100 + $hevc);
            }
            [$latest, $previous] = self::lastTwo($series);
            if ($latest && $previous && ! $latest['stale'] && $rise <= $latest['multi'] - $previous['multi']) {
                $add('rising', $c, sprintf('HEVC %.1f → %.1f (+%.1f จากปีงบ %d, เกณฑ์ +%s)',
                    $previous['multi'], $latest['multi'], $latest['multi'] - $previous['multi'], $previous['year'], rtrim(rtrim(number_format($rise, 1), '0'), '.')), $latest['multi'] - $previous['multi']);
            }
        }

        usort($flags, fn ($a, $b) => [array_search($a['type'], array_keys(self::TYPES)), -$a['sort']] <=> [array_search($b['type'], array_keys(self::TYPES)), -$b['sort']]);
        $counts = array_map(fn () => 0, self::TYPES);
        foreach ($flags as $f) {
            $counts[$f['type']]++;
        }

        return [
            'thresholds' => [
                'mode' => $mode,
                'percent' => $percent,
                'hevc' => $hevcCut,
                'budgetPerPerson' => $budgetCut,
                'recovery' => $recoveryCut,
                'years' => $years,
                'rise' => $rise,
            ],
            'flags' => array_map(fn ($f) => array_diff_key($f, ['sort' => true]), $flags),
            'counts' => $counts,
        ];
    }

    /**
     * The value at the edge of the top (or bottom) $percent of the values: a community "is in" the group when its
     * value is ≥ (or ≤) this. Null with no values.
     *
     * @param  list<float|int|null>  $values
     */
    public static function cut(array $values, float $percent, bool $top): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if (! $values) {
            return null;
        }
        $top ? rsort($values) : sort($values);
        $index = max(0, (int) ceil(count($values) * $percent / 100) - 1);

        return (float) $values[min($index, count($values) - 1)];
    }

    /**
     * The latest two years that have a value (with `year` added).
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private static function lastTwo(array $series): array
    {
        krsort($series);
        $points = [];
        foreach ($series as $year => $p) {
            if ($p['multi'] !== null) {
                $points[] = ['year' => $year] + $p;
            }
        }

        return [$points[0] ?? null, $points[1] ?? null];
    }
}
