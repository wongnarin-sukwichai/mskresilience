<?php

return [
    /*
    | Engine result cache (TOR 4.4-3 / คุณลักษณะ 2.1-3: 20+ users at once on shared hosting). RiskEngine::run()
    | results are kept until any data they depend on changes (App\Scoring\EngineCache::flush() is called from model
    | events, see AppServiceProvider). Off in tests (phpunit.xml), where many tests write with DB::table() between runs.
    */
    'cache' => (bool) env('RISK_ENGINE_CACHE', true),

    // Safety net only: a stale result can't outlive this even if some write path ever skipped the flush.
    'cache_ttl' => (int) env('RISK_ENGINE_CACHE_TTL', 6 * 3600),
];
