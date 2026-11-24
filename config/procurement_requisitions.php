<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Supported Currencies for Mod 044 Purchase Requisitions
    |--------------------------------------------------------------------------
    */
    'supported_currencies' => ['CHF', 'EUR'],

    /*
    |--------------------------------------------------------------------------
    | Default Budget Enforcement Policy (warning | hard_block)
    |--------------------------------------------------------------------------
    */
    'default_budget_enforcement_policy' => 'warning',

    /*
    |--------------------------------------------------------------------------
    | Single-Use Signed One-Click Approval Token TTL (Hours)
    |--------------------------------------------------------------------------
    */
    'one_click_token_ttl_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | Frontend One-Click Action Route Path
    |--------------------------------------------------------------------------
    */
    'frontend_one_click_path' => '/procurement/purchase-requisitions/one-click-action',

    /*
    |--------------------------------------------------------------------------
    | Default Multi-Level Delegation Thresholds (Mod 006 fallback)
    |--------------------------------------------------------------------------
    | Cumulative approval tiers evaluated for CHF and EUR:
    | - < 1,000 CHF/EUR      => Level 1 (Department Manager)
    | - 1,000 - 10,000       => Level 1 + Level 2 (Division Director)
    | - > 10,000 CHF/EUR     => Level 1 + Level 2 + Level 3 (CFO / CEO)
    */
    'default_delegation_tiers' => [
        [
            'level' => 1,
            'approver_type' => 'role',
            'approver_value' => 'department_manager',
            'label' => 'Department Manager',
            'threshold_min' => '0.00',
            'threshold_max' => null,
        ],
        [
            'level' => 2,
            'approver_type' => 'role',
            'approver_value' => 'division_director',
            'label' => 'Division Director',
            'threshold_min' => '1000.00',
            'threshold_max' => null,
        ],
        [
            'level' => 3,
            'approver_type' => 'role',
            'approver_value' => 'cfo',
            'label' => 'CFO / CEO',
            'threshold_min' => '10000.01',
            'threshold_max' => null,
        ],
    ],
];
