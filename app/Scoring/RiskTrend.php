<?php

namespace App\Scoring;

/**
 * HEVC per fiscal year, for the trend views (TOR 4.1.2 "วิเคราะห์แนวโน้ม HEVC รายชุมชน", คุณลักษณะ 2.6-1) and the
 * "เสี่ยงสูงต่อเนื่อง" alert (คุณลักษณะ 2.6-2). Each year is the engine as of 30 Sep of that year (the latest year =
 * all data), the same rounds as เปรียบเทียบรายปี; runs are cached by EngineCache.
 *
 * A community with no new record in a year carries its older data forward in the engine. That point is marked
 * `stale` (its data date belongs to an earlier fiscal year), so a flat line isn't read as "no change".
 */
class RiskTrend
{
    /** Levels that count as "high" for the continuous-high alert: ค่อนข้างสูง and สูง. */
    public const HIGH_LEVELS = ['high', 'very_high'];

    /**
     * @return array{
     *     years: list<int>,
     *     city: array<int, array{multi: float|null, level: string|null, hazards: array<string, float|null>}>,
     *     communities: array<string, array<int, array{multi: float|null, level: string|null, dominant: string|null, hazards: array<string, float|null>, parts: array<string, array{e: float|int|null, v: float|null, cRaw: float|null}>, budget: float|null, dataDate: string|null, stale: bool}>>,
     * }
     */
    public static function series(): array
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $city = [];
        $communities = [];

        foreach ($years as $year) {
            $result = (new RiskEngine)->run($year === $latest ? null : RiskEngine::fiscalYearEnd($year));
            $city[$year] = [
                'multi' => $result['city']['multi']['value'],
                'level' => $result['city']['multi']['level'],
                'hazards' => array_map(fn ($h) => $h['value'], $result['city']['hazards']),
            ];
            foreach ($result['communities'] as $c) {
                $communities[$c['code']][$year] = [
                    'multi' => $c['multi']['hevc'] ?? null,
                    'level' => $c['multi']['level'] ?? null,
                    'dominant' => $c['multi']['dominant'] ?? null,
                    'hazards' => $c['hazards'] ? array_map(fn ($h) => $h['hevc'], $c['hazards']) : [],
                    // E, V and C of each hazard, for "which part moved" in the before–after view.
                    'parts' => $c['hazards'] ? array_map(fn ($h) => ['e' => $h['e'], 'v' => $h['v'], 'cRaw' => $h['cRaw']], $c['hazards']) : [],
                    'budget' => isset($c['inputs']['m_budget_avg_3y']) ? (float) $c['inputs']['m_budget_avg_3y'] : null,
                    'dataDate' => $c['dataDate'],
                    'stale' => $c['dataDate'] !== null && CriteriaSets::fiscalYear($c['dataDate']) < $year,
                ];
            }
        }

        return ['years' => $years, 'city' => $city, 'communities' => $communities];
    }

    /**
     * How many of the latest years in a row the community was ค่อนข้างสูง or สูง, counting only years with its own
     * new data (a stale year breaks the run: it isn't evidence of another high year).
     *
     * @param  array<int, array{level: string|null, stale: bool}>  $byYear  fiscal year → point, any order
     */
    public static function consecutiveHigh(array $byYear): int
    {
        krsort($byYear);
        $run = 0;
        foreach ($byYear as $point) {
            if ($point['stale'] || ! in_array($point['level'], self::HIGH_LEVELS, true)) {
                break;
            }
            $run++;
        }

        return $run;
    }
}
