<?php

return [
    /*
    | Executive Summary in AI mode (TOR 4.1.1(3)-4; owner 2026-11-23). Off unless ตั้งค่าทั่วไป → summary_mode = ai
    | AND a key is set here. Only aggregated, community-level numbers are sent (App\Scoring\ExecutiveSummary::facts()),
    | never personal data. Every call is logged with its tokens and cost (ai_summaries).
    */
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('AI_SUMMARY_MODEL', 'claude-opus-5-5'),

    // USD per 1M tokens for the model above (Claude Opus 5.5), for the cost shown on the page.
    'price_input_per_mtok' => (float) env('AI_PRICE_INPUT', 4.0),
    'price_output_per_mtok' => (float) env('AI_PRICE_OUTPUT', 20.0),

    // Only for showing costs in baht; an approximate rate, labelled as such.
    'usd_to_thb' => (float) env('AI_USD_TO_THB', 33.0),
];
