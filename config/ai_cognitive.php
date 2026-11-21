<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Services & Cognitive Automation Layer (Mod 093)
|--------------------------------------------------------------------------
|
| The layer sits on top of the Copilot chat models and the AI gateway consent: a tenant chooses a local provider (Ollama,
| zero egress) or brings its own key (OpenAI, Anthropic, Gemini, Azure OpenAI). The router never hands data classified
| CONFIDENTIAL_PII to a hosted provider unless the tenant switched the PII masking on.
|
*/

return [
    'default_provider' => env('AI_COGNITIVE_DEFAULT_PROVIDER', 'ollama'),

    // Public list prices, USD per 1,000 tokens (prompt, completion). A local model costs nothing.
    'pricing' => [
        'ollama' => [0.0, 0.0],
        'openai' => [0.00015, 0.0006],
        'anthropic' => [0.003, 0.015],
        'gemini' => [0.000075, 0.0003],
        'azure_openai' => [0.00015, 0.0006],
    ],

    'defaults' => [
        'ollama' => ['endpoint' => env('OLLAMA_BASE_URL', 'http://localhost:11434'), 'model' => 'llama3.3', 'light_model' => 'mistral-nemo', 'embedding_model' => 'nomic-embed-text'],
        'openai' => ['endpoint' => 'https://api.openai.com/v1', 'model' => 'gpt-4o', 'light_model' => 'gpt-4o-mini', 'embedding_model' => 'text-embedding-3-small'],
        'anthropic' => ['endpoint' => 'https://api.anthropic.com', 'model' => 'claude-sonnet-5-5', 'light_model' => 'claude-haiku-5-5', 'embedding_model' => null],
        'gemini' => ['endpoint' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-1.5-pro', 'light_model' => 'gemini-1.5-flash', 'embedding_model' => 'text-embedding-004'],
        'azure_openai' => ['endpoint' => null, 'model' => null, 'light_model' => null, 'embedding_model' => null],
    ],

    'resilience' => [
        'max_attempts' => 3,
        'backoff_ms' => 200,
        'circuit_failure_threshold' => 5,
        'circuit_open_seconds' => 60,
        'cache_ttl_seconds' => 3600,
        'rate_limit_per_minute' => 60,
    ],

    'automation' => [
        'default_auto_approve_confidence' => 0.95,
        'default_max_auto_amount' => 1000,
        'suggestion_ttl_days' => 30,
    ],

    'rag' => [
        'chunk_chars' => 900,
        'top_k' => 5,
        'candidates' => 2000,
        'embedding_batch' => 50,
    ],

    'query' => [
        'max_rows' => 200,
    ],

    'anomaly' => [
        'duplicate_window_days' => 365,
        'amount_tolerance' => 0.01,
    ],

    'reconciliation' => [
        'auto_threshold' => 0.9,
        'amount_tolerance' => 0.05,
        'date_window_days' => 45,
    ],
];
