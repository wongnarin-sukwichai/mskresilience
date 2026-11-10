<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Scoring\RiskEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า 2 ภาพรวมความเสี่ยงของเมือง (spec v5 §2). Everything comes live from RiskEngine. The ช่วงข้อมูล filter is a
 * fiscal year: the latest one shows all data, an earlier one shows the data as of its 30 Sep. The hazard filter
 * is handled in the page, since the engine already returns every hazard.
 */
class RiskOverviewController extends Controller
{
    public function index(Request $request): Response
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : $latest;
        $asOf = $year !== null && $year !== $latest ? RiskEngine::fiscalYearEnd($year) : null;

        $result = $year === null ? null : (new RiskEngine)->run($asOf);

        return Inertia::render('admin/RiskOverview', [
            'fiscalYears' => $years,
            'year' => $year,
            'city' => $result['city'] ?? null,
            // Only what the Top 5 needs; per-community detail belongs to page 3.
            'communities' => $result ? array_map(fn ($c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'status' => $c['status'],
                'multi' => $c['multi'] ? ['hevc' => $c['multi']['hevc'], 'level' => $c['multi']['level'], 'dominant' => $c['multi']['dominant']] : null,
                'hazards' => $c['hazards'] ? array_map(fn ($h) => ['hevc' => $h['hevc'], 'level' => $h['level']], $c['hazards']) : null,
            ], $result['communities']) : [],
        ]);
    }
}
