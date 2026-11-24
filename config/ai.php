<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI provider boundaries
|--------------------------------------------------------------------------
|
| Document extraction and the assistant default to the local driver, which sends nothing outside the installation. Any
| driver missing from `local_drivers` counts as external and runs only for a tenant that enabled external providers.
|
*/

return [
    'extraction_driver' => env('AI_EXTRACTION_DRIVER', 'local'),
    'assistant_driver' => env('AI_ASSISTANT_DRIVER', 'local'),
    'local_drivers' => ['local'],

    /*
    |--------------------------------------------------------------------------
    | Enterprise AI gateway drivers
    |--------------------------------------------------------------------------
    |
    | Read by the ai-gateway package (AiGatewayServiceProvider). The defaults keep the current behaviour: a self-hosted
    | Ollama instance. API keys stay empty until a provider is configured; a driver without a key fails and the gateway
    | falls back to fallback_driver.
    |
    */

    'default_driver' => env('AI_DEFAULT_DRIVER', 'ollama'),
    'fallback_driver' => env('AI_FALLBACK_DRIVER', 'ollama'),

    'drivers' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3.2'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        ],
        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
        ],
        'azure' => [
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
        ],
        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
        ],
    ],
];
