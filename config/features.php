<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Premature / Open-Issue ERP Modules Master Switch
    |--------------------------------------------------------------------------
    |
    | Controls whether ERP modules belonging to open GitHub issues (#253-#408)
    | are exposed by default. In production, unfinished modules can be disabled
    | globally or toggled individually per module / tenant.
    |
    */
    'open_issue_modules_enabled' => (bool) env('FEATURE_OPEN_ISSUE_MODULES', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Per-Module Feature Flags (Open Issues #253-#408)
    |--------------------------------------------------------------------------
    */
    'modules' => [
        'credit_management' => env('FEATURE_MODULE_CREDIT_MANAGEMENT'),
        'supplier_portal' => env('FEATURE_MODULE_SUPPLIER_PORTAL'),
        'manufacturing' => env('FEATURE_MODULE_MANUFACTURING'),
        'plm' => env('FEATURE_MODULE_PLM'),
        'quality' => env('FEATURE_MODULE_QUALITY'),
        'wms' => env('FEATURE_MODULE_WMS'),
        'transportation' => env('FEATURE_MODULE_TRANSPORTATION'),
        'customs' => env('FEATURE_MODULE_CUSTOMS'),
        'rebates' => env('FEATURE_MODULE_REBATES'),
        'configurator' => env('FEATURE_MODULE_CONFIGURATOR'),
        'facility' => env('FEATURE_MODULE_FACILITY'),
        'fleet' => env('FEATURE_MODULE_FLEET'),
        'sustainability' => env('FEATURE_MODULE_SUSTAINABILITY'),
        'governance' => env('FEATURE_MODULE_GOVERNANCE'),
        'contract_register' => env('FEATURE_MODULE_CONTRACT_REGISTER'),
        'talent_learning' => env('FEATURE_MODULE_TALENT_LEARNING'),
        'business_intelligence' => env('FEATURE_MODULE_BUSINESS_INTELLIGENCE'),
        'marketplace' => env('FEATURE_MODULE_MARKETPLACE'),
        'document_trust' => env('FEATURE_MODULE_DOCUMENT_TRUST'),
        'digital_preservation' => env('FEATURE_MODULE_DIGITAL_PRESERVATION'),
        'forecasting' => env('FEATURE_MODULE_FORECASTING'),
        'ocr_extraction' => env('FEATURE_MODULE_OCR_EXTRACTION'),
        'kpi_dashboards' => env('FEATURE_MODULE_KPI_DASHBOARDS'),
        'italian_payroll' => env('FEATURE_MODULE_ITALIAN_PAYROLL'),
        'ai_cognitive' => env('FEATURE_MODULE_AI_COGNITIVE'),
        'leases' => env('FEATURE_MODULE_LEASES'),
        'retail' => env('FEATURE_MODULE_RETAIL'),
        'hospitality' => env('FEATURE_MODULE_HOSPITALITY'),
        'construction' => env('FEATURE_MODULE_CONSTRUCTION'),
        'automotive' => env('FEATURE_MODULE_AUTOMOTIVE'),
        'professionals' => env('FEATURE_MODULE_PROFESSIONALS'),
        'associations' => env('FEATURE_MODULE_ASSOCIATIONS'),
        'events' => env('FEATURE_MODULE_EVENTS'),
        'childcare' => env('FEATURE_MODULE_CHILDCARE'),
        'tessera_sanitaria' => env('FEATURE_MODULE_TESSERA_SANITARIA'),
        'rent' => env('FEATURE_MODULE_RENT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical Domain Module Consolidation Map (app/* -> modules/*)
    |--------------------------------------------------------------------------
    |
    | Maps legacy `App\*` domain classes to their canonical `Modules\*` bounded
    | context counterparts to prevent divergence across the 18 overlapping domains.
    |
    */
    'canonical_modules' => [
        'accounting' => 'Modules\\GeneralLedger',
        'appointments' => 'Modules\\Appointments',
        'automotive' => 'Modules\\Automotive',
        'banking' => 'Modules\\Treasury',
        'construction' => 'Modules\\Construction',
        'crm' => 'Modules\\CRM',
        'customs' => 'Modules\\Customs',
        'ecommerce' => 'Modules\\Ecommerce',
        'field_service' => 'Modules\\EnterpriseServiceManagement',
        'fleet_rental' => 'Modules\\Fleet',
        'hr' => 'Modules\\HumanResources',
        'intrastat' => 'Modules\\Tax',
        'inventory' => 'Modules\\Inventory',
        'invoicing' => 'Modules\\Invoicing',
        'payroll' => 'Modules\\Payroll',
        'professionals' => 'Modules\\ProfessionalServices',
        'retail' => 'Modules\\Retail',
        'sales' => 'Modules\\Sales',
    ],
];
