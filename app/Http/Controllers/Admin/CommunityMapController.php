<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Scoring\RiskEngine;
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
                'counts' => $c['counts'],
                'multi' => $c['multi'],
                'hazards' => $c['hazards'],
                'groups' => $c['groups'],
                'recovery' => $c['recovery'],
                'previous' => $c['previous'] ? [
                    'dataDate' => $c['previous']['dataDate'],
                    'multi' => $c['previous']['multi']['hevc'],
                    'hazards' => array_map(fn ($h) => $h['hevc'], $c['previous']['hazards']),
                ] : null,
            ], $result['communities']) : [],
        ]);
    }
}
