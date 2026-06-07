<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    | Which provider powers Streakly's AI features (natural-language logging,
    | weekly insights, smart suggestions). Set to "claude" or "openai".
    | Leave the matching API key blank to silently disable all AI features —
    | the UI hides itself when no key is configured.
    */
    'provider' => env('AI_PROVIDER', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Per-request guardrails
    |--------------------------------------------------------------------------
    */
    'timeout'     => (int) env('AI_TIMEOUT', 30),
    'max_tokens'  => (int) env('AI_MAX_TOKENS', 1024),

    'drivers' => [

        'claude' => [
            'key'     => env('ANTHROPIC_API_KEY'),
            // Defaults to the latest Claude. For cheaper/faster micro-tasks set
            // ANTHROPIC_MODEL=claude-haiku-4-5 in your .env.
            'model'   => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        'openai' => [
            'key'      => env('OPENAI_API_KEY'),
            'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        ],

    ],

];
