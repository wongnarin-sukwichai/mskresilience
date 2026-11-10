<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScoringCriteriaSet;
use App\Scoring\RiskEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ดูวิธีคิด" (admin only, owner 2026-11-10): one community's calculation, step by step, as JSON for the dialog on
 * pages 3 and 4. It returns the trace the engine itself produced while computing, never a separate description,
 * so what is shown is exactly what was calculated. `year` picks the round the same way the pages do. This replaced
 * the temporary /calc-preview mock.
 */
class CalcTraceController extends Controller
{
    public function show(Request $request, string $code): JsonResponse
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : $latest;
        $asOf = $year !== null && $year !== $latest ? RiskEngine::fiscalYearEnd($year) : null;

        $community = collect((new RiskEngine)->run($asOf)['communities'])->firstWhere('code', $code);
        abort_unless($community, 404);

        $set = $community['criteriaSet'] ? ScoringCriteriaSet::find($community['criteriaSet']['id']) : null;

        return response()->json([
            'code' => $community['code'],
            'name' => $community['name'],
            'status' => $community['status'],
            'missingSheets' => $community['missingSheets'],
            'sources' => $community['sources'],
            'dataDate' => $community['dataDate'],
            'population' => $community['population'],
            'criteriaSet' => $community['criteriaSet'],
            'constants' => $set ? [
                'wE' => $set->weights['hazard_ev']['ALL']['E'],
                'wV' => $set->weights['hazard_ev']['ALL']['V'],
                'c' => $set->weights['hazard_c']['ALL']['C'],
            ] : null,
            'hazards' => $community['hazards'],
            'multi' => $community['multi'],
            'recovery' => $community['recovery'],
            'trace' => $community['trace'],
        ]);
    }
}
