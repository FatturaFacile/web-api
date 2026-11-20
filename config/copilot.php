<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ERP Copilot
|--------------------------------------------------------------------------
|
| `driver` and `embedding_driver` name one of `drivers`. The local driver sends nothing outside the installation; the
| other hosted drivers (openai, anthropic, gemini) run only for a tenant that enabled external AI providers (see
| TenantAiConsentInterface). Ollama and vLLM are self-hosted and keep the data inside the installation, which is what Swiss
| nLPD / EU GDPR data residency asks for.
|
*/

return [
    'driver' => env('COPILOT_DRIVER', 'local'),
    'embedding_driver' => env('COPILOT_EMBEDDING_DRIVER', 'local'),

    'drivers' => [
        'local' => [],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('COPILOT_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('COPILOT_OPENAI_MODEL', 'gpt-4o-mini'),
            'embedding_model' => env('COPILOT_OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('COPILOT_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'model' => env('COPILOT_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('COPILOT_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('COPILOT_GEMINI_MODEL', 'gemini-1.5-flash'),
            'embedding_model' => env('COPILOT_GEMINI_EMBEDDING_MODEL', 'text-embedding-004'),
        ],
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('COPILOT_OLLAMA_MODEL', 'llama3.2'),
            'embedding_model' => env('COPILOT_OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        ],
        'vllm' => [
            'api_key' => env('VLLM_API_KEY'),
            'base_url' => env('VLLM_BASE_URL', 'http://localhost:8000/v1'),
            'model' => env('COPILOT_VLLM_MODEL'),
            'embedding_model' => env('COPILOT_VLLM_EMBEDDING_MODEL'),
        ],
    ],

    'quota' => [
        'tenant_daily_tokens' => (int) env('COPILOT_TENANT_DAILY_TOKENS', 500000),
        'user_requests_per_minute' => (int) env('COPILOT_USER_REQUESTS_PER_MINUTE', 20),
    ],

    'limits' => [
        'max_prompt_chars' => 4000,
        'max_tool_iterations' => 5,
        'history_messages' => 12,
        'rag_top_k' => 5,
        'rag_candidates' => 2000,
        'semantic_max_rows' => 20000,
        'semantic_result_rows' => 200,
    ],
];
