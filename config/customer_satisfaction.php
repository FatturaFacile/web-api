<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Customer satisfaction surveys (Mod 245)
    |--------------------------------------------------------------------------
    */

    // Public mobile landing page the one-click links forward to (the survey token is appended).
    'landing_url' => env('CUSTOMER_SATISFACTION_LANDING_URL', rtrim((string) env('APP_FRONTEND_URL', (string) env('APP_URL', 'http://localhost')), '/').'/survey'),

    // Days a survey link stays valid after it was sent.
    'link_expiry_days' => (int) env('CUSTOMER_SATISFACTION_LINK_EXPIRY_DAYS', 30),

    // Default fatigue cooldown and closed-loop SLA of a new template.
    'default_cooldown_days' => 60,
    'default_sla_hours' => 24,

    // Voice comments: disk, and the largest upload in kilobytes.
    'voice_disk' => env('CUSTOMER_SATISFACTION_VOICE_DISK', 'local'),
    'voice_max_kb' => 10240,

    // Tenant roles that receive the closed-loop alerts as support / customer success lead.
    'support_lead_roles' => ['support_manager', 'operations_manager'],

    // Root cause codes offered when a detractor case is resolved (labels live in the frontend dictionary).
    'root_causes' => ['product_defect', 'delivery_delay', 'service_quality', 'billing_error', 'communication', 'pricing', 'staff_behaviour', 'process_complexity', 'other'],

    // Surveys sent by one scheduler tick, per company.
    'batch_size' => 200,
];
