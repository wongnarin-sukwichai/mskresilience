<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\DisasterEvent;
use App\Models\ScoringCriteriaSet;
use App\Scoring\RiskEngine;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า 1 เกี่ยวกับระบบและการอ่านข้อมูล (spec v5 §1). It is mostly fixed text in the page. The only live facts are the
 * latest data date, the number of communities, and the criteria sets, so the page never shows a made-up date.
 * The route keeps the name `dashboard`, because the login redirects target it.
 */
class AboutController extends Controller
{
    public function index(): Response
    {
        $dates = [];
        foreach (RiskEngine::SHEETS as [$model]) {
            $dates[] = $model::query()->max('assessment_date');
        }
        $dates[] = DisasterEvent::query()->max('event_start');
        $dates = array_filter($dates);

        return Inertia::render('admin/About', [
            'dataDate' => $dates ? substr((string) max($dates), 0, 10) : null,
            'communityCount' => Community::count(),
            'criteriaSets' => ScoringCriteriaSet::query()->orderBy('start_fiscal_year')->get(['name', 'start_fiscal_year']),
        ]);
    }
}
