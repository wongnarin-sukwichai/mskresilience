<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Scoring\RiskEngine;
use App\Scoring\RiskTrend;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า 3 แผนที่ความเสี่ยงรายชุมชน (spec v5 §3). Every community's scores come live from RiskEngine. The ช่วงข้อมูล
 * filter works like page 2. Hazard, layer and selected community are handled in the page; `community` and
 * `hazard` in the query string preselect them (page 2's Top 5 links here).
 */
class CommunityMapController extends Controller
{
    public function index(Request $request): Response
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : $latest;
        $asOf = $year !== null && $year !== $latest ? RiskEngine::fiscalYearEnd($year) : null;

        $result = $year === null ? null : (new RiskEngine)->run($asOf);

        return Inertia::render('admin/CommunityMap', [
            'fiscalYears' => $years,
            'year' => $year,
            'initialCommunity' => $request->query('community'),
            'initialHazard' => $request->query('hazard'),
            'cityDominant' => $result['city']['multi']['dominant'] ?? null,
            // The panel's data per community; the calculation trace stays server-side.
            'communities' => $result ? array_map(fn ($c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'status' => $c['status'],
                'missingSheets' => $c['missingSheets'],
                'dataDate' => $c['dataDate'],
                'criteriaSet' => $c['criteriaSet'],
                'population' => $c['population'],
                'households' => $c['counts']['households'],
                'areaKm2' => $c['areaKm2'],
                'responseHours' => $c['responseHours'],
                'multi' => $c['multi'],
                'hazards' => $c['hazards'],
                'recovery' => $c['recovery'],
                // R1–R5 readiness for the radar (TOR 4.1.1(4)); only these 5 inputs leave the server.
                'recoveryItems' => self::recoveryItems($c['inputs'] ?? []),
                'previous' => $c['previous'] ? [
                    'dataDate' => $c['previous']['dataDate'],
                    'multi' => $c['previous']['multi']['hevc'],
                    'hazards' => array_map(fn ($h) => $h['hevc'], $c['previous']['hazards']),
                ] : null,
            ], $result['communities']) : [],
            // City average of R1–R5 (population-weighted, like every city value), the radar's comparison line.
            'cityRecoveryItems' => $result ? self::cityRecoveryItems($result['communities']) : null,
            // HEVC per fiscal year per community, for the panel's trend line (TOR 4.1.2, คุณลักษณะ 2.6-1).
            'trend' => $year === null ? null : self::trend(),
        ]);
    }

    public const RECOVERY_COLUMNS = [
        'R1' => 'r1_recovery_plan_score',
        'R2' => 'r2_build_back_better_score',
        'R3' => 'r3_socioeconomic_recov_score',
        'R4' => 'r4_vulner_group_recov_score',
        'R5' => 'r5_learning_sys_improv_score',
    ];

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, float|null>
     */
    public static function recoveryItems(array $inputs): array
    {
        return array_map(fn ($column) => isset($inputs[$column]) ? (float) $inputs[$column] : null, self::RECOVERY_COLUMNS);
    }

    /**
     * @param  list<array<string, mixed>>  $communities
     * @return array<string, float|null>
     */
    public static function cityRecoveryItems(array $communities): array
    {
        $out = [];
        foreach (self::RECOVERY_COLUMNS as $key => $column) {
            $sum = 0.0;
            $weight = 0;
            foreach ($communities as $c) {
                $value = $c['inputs'][$column] ?? null;
                if ($c['status'] === 'ok' && $value !== null && $c['population']) {
                    $sum += (float) $value * $c['population'];
                    $weight += $c['population'];
                }
            }
            $out[$key] = $weight ? $sum / $weight : null;
        }

        return $out;
    }

    /**
     * @return array{years: list<int>, communities: array<string, list<array{year: int, multi: float|null, level: string|null, hazards: array<string, float|null>, stale: bool}>>, consecutiveHigh: array<string, int>}
     */
    private static function trend(): array
    {
        $series = RiskTrend::series();
        $communities = [];
        $consecutive = [];
        foreach ($series['communities'] as $code => $byYear) {
            $communities[$code] = array_values(array_map(fn ($year, $p) => ['year' => $year, ...$p], array_keys($byYear), $byYear));
            $consecutive[$code] = RiskTrend::consecutiveHigh($byYear);
        }

        return ['years' => $series['years'], 'communities' => $communities, 'consecutiveHigh' => $consecutive];
    }
}
