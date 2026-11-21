<?php

declare(strict_types=1);

/*
 * Intelligent document and invoice OCR extraction (Mod 092).
 *
 * Local engines are the default. Cloud engines run only for a tenant that consented to external AI providers, with the
 * API key of the tenant (stored encrypted in tenant_ocr_configurations), never a key of the platform.
 */
return [
    // Dedicated worker queue: `php artisan queue:work --queue=ai-ocr`.
    'queue' => env('OCR_EXTRACTION_QUEUE', 'ai-ocr'),
    'timeout_seconds' => (int) env('OCR_EXTRACTION_TIMEOUT', 120),

    'ollama' => [
        'endpoint' => env('OCR_OLLAMA_ENDPOINT', 'http://localhost:11434'),
        'model' => env('OCR_OLLAMA_MODEL', 'qwen2.5vl'),
    ],
    'gemini' => [
        'endpoint' => env('OCR_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('OCR_GEMINI_MODEL', 'gemini-2.0-flash'),
    ],
    'openai' => [
        'endpoint' => env('OCR_OPENAI_ENDPOINT', 'https://api.openai.com/v1'),
        'model' => env('OCR_OPENAI_MODEL', 'gpt-4o'),
    ],
    'azure' => [
        'poll_attempts' => (int) env('OCR_AZURE_POLL_ATTEMPTS', 30),
        'poll_interval_ms' => (int) env('OCR_AZURE_POLL_INTERVAL_MS', 1000),
    ],
    'tesseract' => [
        'binary' => env('OCR_TESSERACT_BINARY', 'tesseract'),
    ],

    // Shared secret with which the mail gateway signs inbound-email webhooks (HMAC-SHA256 of the raw body).
    'inbound_email_secret' => env('OCR_INBOUND_EMAIL_SECRET'),
    'inbound_email_domain' => env('OCR_INBOUND_EMAIL_DOMAIN', 'fatturafacile.ch'),
    'inbound_max_attachment_bytes' => 15 * 1024 * 1024,

    // Folder of the DMS that feeds the AI Inbox.
    'inbox_folder_name' => 'AI Inbox',

    'signed_url_minutes' => 15,

    // Minimum similarity of a line description to a learned pattern for a fuzzy coding suggestion.
    'coding_similarity_threshold' => 0.6,

    // Three-way match tolerance when the tenant has no procurement settings.
    'match_tolerance_percent' => 2.0,
    'match_tolerance_amount' => 1.0,
];
