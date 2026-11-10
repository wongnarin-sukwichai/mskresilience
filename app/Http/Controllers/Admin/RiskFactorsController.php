<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScoringCriteriaSet;
use App\Scoring\Bands;
use App\Scoring\HevcCalculator;
use App\Scoring\RiskEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า 4 ปัจจัยความเสี่ยงและความพร้อม (spec v5 §4). Owner decisions (2026-11-10):
 * - Scope is the whole city (population-weighted) by default, or one community (`?community=CODE`, page 3 links here).
 * - The 5 hazard tabs default to the city's dominant hazard.
 * - Items in the layout picture with no input column (ครัวเรือนเปิดรับภัย, พื้นที่เสี่ยง, other gap types) are left out.
 * - A "ที่มาของคะแนน" block shows V_h and C_h with their components and weights, so the weights of every set in use
 *   are sent along.
 */
class RiskFactorsController extends Controller
{
    /** The weight sections the score-origin blocks show. */
    private const WEIGHT_SECTIONS = ['v_hazard', 'c_hazard'];

    public function index(Request $request): Response
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : $latest;
        $asOf = $year !== null && $year !== $latest ? RiskEngine::fiscalYearEnd($year) : null;

        $result = $year === null ? null : (new RiskEngine)->run($asOf);
        $city = $result['city'] ?? null;

        // City E: exposed people over population across the scored communities, banded like a community's E.
        $cityExposure = [];
        if ($city) {
            foreach (HevcCalculator::HAZARDS as $h) {
                $count = $city['counts']['e_numerator_count_'.strtolower($h)] ?? 0;
                $pct = $city['counts']['population'] > 0 ? $count / $city['counts']['population'] * 100 : null;
                $cityExposure[$h] = ['count' => $count, 'percent' => $pct, 'score' => Bands::percent($pct)];
            }
        }

        $setIds = $result ? collect($result['communities'])->pluck('criteriaSet.id')->filter()->unique()->values() : collect();
        $weights = ScoringCriteriaSet::query()->whereIn('id', $setIds)->get()
            ->mapWithKeys(fn ($set) => [$set->id => array_intersect_key($set->weights, array_flip(self::WEIGHT_SECTIONS))]);

        return Inertia::render('admin/RiskFactors', [
            'fiscalYears' => $years,
            'year' => $year,
            'initialCommunity' => $request->query('community'),
            'initialHazard' => $request->query('hazard'),
            'city' => $city ? [
                'dominant' => $city['multi']['dominant'],
                'hazards' => $city['hazards'],
                'exposure' => $cityExposure,
                'groups' => $city['groups'],
                'groupDeltas' => $city['groupDeltas'],
                'counts' => $city['counts'],
                'scoredCommunities' => $city['scoredCommunities'],
                'totalCommunities' => $city['totalCommunities'],
                'dataDate' => $city['dataDate'],
                'previousDataDate' => $city['previousDataDate'],
                'criteriaSets' => $city['criteriaSets'],
            ] : null,
            'communities' => $result ? array_map(fn ($c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'status' => $c['status'],
                'missingSheets' => $c['missingSheets'],
                'dataDate' => $c['dataDate'],
                'criteriaSet' => $c['criteriaSet'],
                'population' => $c['population'],
                'counts' => $c['counts'],
                'hazards' => $c['hazards'],
                'groups' => $c['groups'],
                'previous' => $c['previous'] ? [
                    'dataDate' => $c['previous']['dataDate'],
                    'groups' => $c['previous']['groups'],
                    'hazards' => array_map(fn ($h) => ['v' => $h['v'], 'cRaw' => $h['cRaw']], $c['previous']['hazards']),
                ] : null,
            ], $result['communities']) : [],
            'weights' => $weights,
        ]);
    }
}
