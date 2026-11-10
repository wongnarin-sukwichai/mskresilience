<?php

namespace App\Http\Controllers;

use App\Scoring\RiskEngine;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public homepage (Welcome.vue), open to everyone without logging in. Owner decision 2026-11-10 (option ก):
 * the public sees each community's risk level, live from RiskEngine with the latest data, like page 3 does.
 *
 * Only the scores, levels and dominant hazard go out. Inputs, counts, population, criteria sets and the
 * calculation trace stay server-side, because this page is public.
 */
class WelcomeController extends Controller
{
    public function __invoke(): Response
    {
        $result = RiskEngine::fiscalYears() ? (new RiskEngine)->run() : null;

        return Inertia::render('Welcome', [
            'openLogin' => (bool) session('openLogin'),
            'risk' => $result ? [
                'dataDate' => $result['city']['dataDate'],
                'city' => [
                    'hevc' => $result['city']['multi']['value'],
                    'level' => $result['city']['multi']['level'],
                    'dominant' => $result['city']['multi']['dominant'],
                    'communities' => $result['city']['multi']['communities'],
                ],
                'communities' => array_map(fn ($c) => [
                    'code' => $c['code'],
                    'status' => $c['status'],
                    'dataDate' => $c['dataDate'],
                    'multi' => $c['multi'],
                    'hazards' => $c['hazards'] === null ? null : array_map(
                        fn ($h) => ['hevc' => $h['hevc'], 'level' => $h['level']],
                        $c['hazards'],
                    ),
                ], $result['communities']),
            ] : null,
        ]);
    }
}
