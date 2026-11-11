<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSets;
use App\Scoring\HevcCalculator;
use App\Scoring\RiskEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เปรียบเทียบรายปี (owner-approved from the /compare-preview mock, 2026-11-11). Two fiscal years A and B, each run
 * through RiskEngine as of its 30 Sep (or all data for the latest year), so every community uses its latest record
 * on or before that date and the criteria set of that record's fiscal year, exactly as on the other pages.
 *
 * - A community whose record is the same on both sides got no new import in between: "stale", left out of every Δ.
 * - City values are population-weighted like RiskEngine::city(). The city and hazard Δ cover only the communities
 *   with new data on both sides.
 * - When the two sides use different criteria sets, each hazard's Δ is split into data vs criteria by running side
 *   A's data again with side B's set (an engine override, never stored or shown as a result).
 * - The main cause per hazard is the E, V or C term that moved the HEVC most in the direction of the change
 *   (midpoint split of the HEVC formula, using each community's side-B weights).
 * - Classifying communities as ลดลง/เพิ่มขึ้น/ทรงตัว depends on the page's "ค่าความต่างที่รับได้" setting, so the
 *   page does that from the per-community values sent here.
 */
class YearComparisonController extends Controller
{
    public function index(Request $request): Response
    {
        $years = RiskEngine::fiscalYears();
        if (count($years) < 2) {
            return Inertia::render('admin/YearComparison', ['fiscalYears' => $years, 'yearA' => null, 'yearB' => null, 'result' => null]);
        }

        $latest = max($years);
        $b = in_array((int) $request->query('b'), $years, true) ? (int) $request->query('b') : $latest;
        $a = in_array((int) $request->query('a'), $years, true) && (int) $request->query('a') !== $b
            ? (int) $request->query('a')
            : (collect($years)->filter(fn ($y) => $y < $b)->max() ?? collect($years)->first(fn ($y) => $y !== $b));

        $asOf = fn (int $year) => $year === $latest ? null : RiskEngine::fiscalYearEnd($year);
        $runA = (new RiskEngine)->run($asOf($a));
        $runB = (new RiskEngine)->run($asOf($b));
        $sets = ScoringCriteriaSet::all()->keyBy('id');

        $sideA = collect($runA['communities'])->keyBy('code');
        $pairs = [];
        foreach ($runB['communities'] as $cb) {
            $ca = $sideA->get($cb['code']);
            $okA = $ca && $ca['status'] === 'ok';
            $okB = $cb['status'] === 'ok';
            $pairs[] = [
                'code' => $cb['code'],
                'name' => $cb['name'],
                'a' => $okA ? $ca : null,
                'b' => $okB ? $cb : null,
                'stale' => $okA && $okB && $ca['dataDate'] === $cb['dataDate'],
            ];
        }
        $comparable = array_values(array_filter($pairs, fn ($p) => $p['a'] && $p['b'] && ! $p['stale']));

        // Side A recomputed with side B's set, for the communities whose set differs (data vs criteria split).
        $aOnB = [];
        $neededSets = collect($comparable)
            ->filter(fn ($p) => $p['a']['criteriaSet']['id'] !== $p['b']['criteriaSet']['id'])
            ->pluck('b.criteriaSet.id')->unique();
        foreach ($neededSets as $setId) {
            $rerun = collect((new RiskEngine)->run($asOf($a), $sets[$setId])['communities'])->keyBy('code');
            foreach ($comparable as $p) {
                if ($p['b']['criteriaSet']['id'] === $setId && $p['a']['criteriaSet']['id'] !== $setId) {
                    $aOnB[$p['code']] = $rerun[$p['code']];
                }
            }
        }
        $setsDiffer = $aOnB !== [];

        $hazards = [];
        foreach (HevcCalculator::HAZARDS as $h) {
            $list = array_filter($comparable, fn ($p) => $p['a']['hazards'][$h]['hevc'] !== null && $p['b']['hazards'][$h]['hevc'] !== null);
            $va = $this->weighted($list, fn ($p) => $p['a']['hazards'][$h]['hevc']);
            $vb = $this->weighted($list, fn ($p) => $p['b']['hazards'][$h]['hevc']);
            $onB = $setsDiffer
                ? $this->weighted($list, fn ($p) => ($aOnB[$p['code']] ?? $p['a'])['hazards'][$h]['hevc'])
                : null;
            $hazards[$h] = [
                'a' => $va,
                'b' => $vb,
                'delta' => $va !== null && $vb !== null ? $vb - $va : null,
                'fromData' => $onB !== null && $vb !== null ? $vb - $onB : null,
                'fromCriteria' => $onB !== null && $va !== null ? $onB - $va : null,
                'cause' => $this->cause($list, $h, $sets),
                'communities' => count($list),
            ];
        }

        $side = fn (array $run, int $year) => [
            'year' => $year,
            'asOf' => $asOf($year),
            'latest' => $year === $latest,
            'multi' => $run['city']['multi']['value'],
            'communities' => $run['city']['multi']['communities'],
        ];
        $cityA = $this->weighted($comparable, fn ($p) => $p['a']['multi']['hevc']);
        $cityB = $this->weighted($comparable, fn ($p) => $p['b']['multi']['hevc']);

        return Inertia::render('admin/YearComparison', [
            'fiscalYears' => $years,
            'yearA' => $a,
            'yearB' => $b,
            'result' => [
                'a' => $side($runA, $a),
                'b' => $side($runB, $b),
                'delta' => $cityA !== null && $cityB !== null ? $cityB - $cityA : null,
                'comparable' => count($comparable),
                'setsDiffer' => $setsDiffer,
                'hazards' => $hazards,
                // Only what the table and the A/B cards need; never inputs or trace.
                'communities' => array_map(fn ($p) => [
                    'code' => $p['code'],
                    'name' => $p['name'],
                    'stale' => $p['stale'],
                    'a' => $this->sideOf($p['a']),
                    'b' => $this->sideOf($p['b']),
                ], $pairs),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $c
     * @return array<string, mixed>|null
     */
    private function sideOf(?array $c): ?array
    {
        if (! $c) {
            return null;
        }

        return [
            'multi' => $c['multi']['hevc'],
            'hazards' => array_map(fn ($h) => $h['hevc'], $c['hazards']),
            'dataDate' => $c['dataDate'],
            'fiscalYear' => CriteriaSets::fiscalYear($c['dataDate']),
            'criteriaSet' => ['id' => $c['criteriaSet']['id'], 'name' => $c['criteriaSet']['name']],
        ];
    }

    /**
     * Population-weighted mean over pairs, skipping null values and communities without population (as
     * RiskEngine::city() does). Population is taken from side B.
     *
     * @param  array<int, array<string, mixed>>  $pairs
     */
    private function weighted(array $pairs, callable $value): ?float
    {
        $sum = 0.0;
        $weight = 0;
        foreach ($pairs as $p) {
            $v = $value($p);
            $pop = $p['b']['population'] ?? null;
            if ($v === null || ! $pop) {
                continue;
            }
            $sum += $v * $pop;
            $weight += $pop;
        }

        return $weight > 0 ? $sum / $weight : null;
    }

    /**
     * Which of E, V or C moved this hazard's HEVC most in the direction of its change, from the midpoint split of
     * HEVC = 20·(wE·E + wV·V)·(1 − c·C_HEVC): E part = 20·wE·ΔE·(1 − c·C̄), V part = 20·wV·ΔV·(1 − c·C̄),
     * C part = −20·(wE·Ē + wV·V̄)·c·ΔC_HEVC. Population-weighted, side-B weights. Null when nothing moved.
     *
     * @param  array<int, array<string, mixed>>  $pairs
     * @param  Collection<int, ScoringCriteriaSet>  $sets
     * @return array{driver: string, direction: string}|null
     */
    private function cause(array $pairs, string $h, $sets): ?array
    {
        $parts = ['E' => 0.0, 'V' => 0.0, 'C' => 0.0];
        $total = 0.0;
        foreach ($pairs as $p) {
            $ha = $p['a']['hazards'][$h];
            $hb = $p['b']['hazards'][$h];
            $pop = $p['b']['population'] ?? 0;
            $weights = $sets[$p['b']['criteriaSet']['id']]->weights ?? null;
            if (! $pop || ! $weights || $ha['e'] === null || $hb['e'] === null || $ha['v'] === null || $hb['v'] === null || $ha['cHevc'] === null || $hb['cHevc'] === null) {
                continue;
            }
            $wE = $weights['hazard_ev']['ALL']['E'];
            $wV = $weights['hazard_ev']['ALL']['V'];
            $c = $weights['hazard_c']['ALL']['C'];
            $k = 1 - $c * ($ha['cHevc'] + $hb['cHevc']) / 2;
            $ev = $wE * ($ha['e'] + $hb['e']) / 2 + $wV * ($ha['v'] + $hb['v']) / 2;
            $parts['E'] += 20 * $wE * ($hb['e'] - $ha['e']) * $k * $pop;
            $parts['V'] += 20 * $wV * ($hb['v'] - $ha['v']) * $k * $pop;
            $parts['C'] += -20 * $ev * $c * ($hb['cHevc'] - $ha['cHevc']) * $pop;
            $total += ($hb['hevc'] - $ha['hevc']) * $pop;
        }
        if (abs($total) < 1e-9) {
            return null;
        }
        $sign = $total > 0 ? 1 : -1;
        $best = null;
        foreach ($parts as $key => $value) {
            if ($value * $sign > 0 && ($best === null || abs($value) > abs($parts[$best]))) {
                $best = $key;
            }
        }

        return $best === null ? null : ['driver' => $best, 'direction' => $sign > 0 ? 'up' : 'down'];
    }
}
