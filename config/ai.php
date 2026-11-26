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

    /*
    | Cost comparison only (owner 2026-11-26): what the same summary would cost on other vendors' models. The system
    | never calls Gemini or OpenAI; these are list prices (USD per 1M tokens, standard tier, short prompts) copied from
    | each vendor's official pricing page on `checked_at`. Prices change: re-check the `source` pages and update both
    | the numbers and `checked_at`. Each vendor counts Thai text into tokens differently, so the comparison assumes the
    | same token counts and is an estimate only (the page says so).
    */
    'compare_checked_at' => '2026-11-26',
    'compare' => [
        [
            'vendor' => 'Claude',
            'company' => 'Anthropic',
            'source' => 'https://platform.claude.com/docs/en/about-claude/pricing',
            'models' => [
                ['name' => 'Claude Opus 5.5', 'input' => 4.0, 'output' => 20.0, 'note' => 'รุ่นที่ระบบใช้อยู่'],
                ['name' => 'Claude Sonnet 5.5', 'input' => 2.0, 'output' => 10.0],
                ['name' => 'Claude Haiku 5.5', 'input' => 0.10, 'output' => 0.50],
            ],
        ],
        [
            'vendor' => 'Gemini',
            'company' => 'Google',
            'source' => 'https://aistudio.google.com/docs/pricing',
            'models' => [
                ['name' => 'Gemini 4 Argon', 'input' => 2.0, 'output' => 10.0, 'note' => 'ขึ้นเป็น $4 / $20 ตั้งแต่ 1 ธ.ค. 2569'],
                ['name' => 'Gemini 3.8 Flash', 'input' => 0.75, 'output' => 3.75, 'note' => 'ขึ้นเป็น $1.50 / $7.50 ตั้งแต่ 1 ม.ค. 2570'],
                ['name' => 'Gemini 3.5 Flash-Lite', 'input' => 0.30, 'output' => 2.50],
            ],
        ],
        [
            'vendor' => 'ChatGPT',
            'company' => 'OpenAI',
            'source' => 'https://developers.openai.com/api/docs/pricing',
            'models' => [
                ['name' => 'GPT-6.2 Astra', 'input' => 8.0, 'output' => 40.0],
                ['name' => 'GPT-6.2 Sol', 'input' => 2.0, 'output' => 10.0],
                ['name' => 'GPT-6 Luna', 'input' => 0.10, 'output' => 0.50],
            ],
        ],
    ],
];
