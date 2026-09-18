<?php

declare(strict_types=1);

return [

    'llm' => [

        'driver' => env('GRIDWISE_LLM_DRIVER', 'gemini'),

        'timeout' => (float) env('GRIDWISE_LLM_TIMEOUT', 12.0),
        'connect_timeout' => (float) env('GRIDWISE_LLM_CONNECT_TIMEOUT', 4.0),
        'retries' => (int) env('GRIDWISE_LLM_RETRIES', 2),
        'retry_delay_ms' => (int) env('GRIDWISE_LLM_RETRY_DELAY_MS', 250),

        'cache' => [
            'enabled' => env('GRIDWISE_LLM_CACHE', true),
            'ttl' => (int) env('GRIDWISE_LLM_CACHE_TTL', 3600),
        ],

        'drivers' => [

            'gemini' => [
                'key' => env('GEMINI_API_KEY'),
                'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 0),
            ],

            'openai' => [
                'key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],

            'groq' => [
                'key' => env('GROQ_API_KEY'),
                'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
                'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            ],

            'anthropic' => [
                'key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
                'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            ],

            'ollama' => [
                'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
                'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            ],

        ],
    ],

    'fallback' => [
        'enabled' => env('GRIDWISE_FALLBACK', true),
    ],

    'tolerance' => [
        'judge' => 0.01,
        'solver' => 1.0e-6,
        'output_decimals' => 6,
    ],

    'optimizer' => [
        'max_iterations' => (int) env('GRIDWISE_LP_MAX_ITERATIONS', 20000),
        'bland_after' => (int) env('GRIDWISE_LP_BLAND_AFTER', 4000),
    ],

];
