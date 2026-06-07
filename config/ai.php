<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    | Which provider powers Streakly's AI features (natural-language logging,
    | weekly insights, smart suggestions). One of: claude, openai, groq,
    | openrouter, ollama. Every provider except "claude" speaks the
    | OpenAI-compatible chat-completions API, so free/open-source backends
    | (Groq, OpenRouter, Ollama, Together, …) work out of the box.
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

        // Groq — free, fast, open models (Llama, etc.). Key: https://console.groq.com/keys
        'groq' => [
            'key'      => env('GROQ_API_KEY'),
            'model'    => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai'),
        ],

        // OpenRouter — aggregates many models, including free ":free" variants.
        // Key: https://openrouter.ai/keys
        'openrouter' => [
            'key'      => env('OPENROUTER_API_KEY'),
            'model'    => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api'),
        ],

        // Ollama — 100% local & offline, no key, no internet. https://ollama.com
        // The placeholder key just keeps the feature "enabled"; Ollama ignores auth.
        'ollama' => [
            'key'      => env('OLLAMA_API_KEY', 'ollama'),
            'model'    => env('OLLAMA_MODEL', 'llama3.2'),
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

    ],

];
