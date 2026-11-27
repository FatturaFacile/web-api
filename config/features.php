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
        'bi_dss' => env('FEATURE_MODULE_BI_DSS'),
        'kpi_dashboards' => env('FEATURE_MODULE_KPI_DASHBOARDS'),
        'italian_payroll' => env('FEATURE_MODULE_ITALIAN_PAYROLL'),
        'hse' => env('FEATURE_MODULE_HSE'),
        'cpq_quotes' => env('FEATURE_MODULE_CPQ_QUOTES'),
        'cpq_configurator' => env('FEATURE_MODULE_CPQ_CONFIGURATOR'),
        'swiss_fiscal' => env('FEATURE_MODULE_SWISS_FISCAL'),
        'swiss_vat' => env('FEATURE_MODULE_SWISS_VAT'),
        'leave_management' => env('FEATURE_MODULE_LEAVE_MANAGEMENT'),
        'marketing_automation' => env('FEATURE_MODULE_MARKETING_AUTOMATION'),
        'agent_commissions' => env('FEATURE_MODULE_AGENT_COMMISSIONS'),
        'debt_collection' => env('FEATURE_MODULE_DEBT_COLLECTION'),
        'expense_management' => env('FEATURE_MODULE_EXPENSE_MANAGEMENT'),
        'tender_management' => env('FEATURE_MODULE_TENDER_MANAGEMENT'),
        'gdo_trade_terms' => env('FEATURE_MODULE_GDO_TRADE_TERMS'),
        'industrial_laundry' => env('FEATURE_MODULE_INDUSTRIAL_LAUNDRY'),
        'statutory_financials' => env('FEATURE_MODULE_STATUTORY_FINANCIALS'),
        'passenger_transport' => env('FEATURE_MODULE_PASSENGER_TRANSPORT'),
        'customer_satisfaction' => env('FEATURE_MODULE_CUSTOMER_SATISFACTION'),
        'swiss_ebill' => env('FEATURE_MODULE_SWISS_EBILL'),
        'franchise_network' => env('FEATURE_MODULE_FRANCHISE_NETWORK'),
        'distribution' => env('FEATURE_MODULE_DISTRIBUTION'),
        'corporate_conventions' => env('FEATURE_MODULE_CORPORATE_CONVENTIONS'),
        'product_catalog' => env('FEATURE_MODULE_PRODUCT_CATALOG'),
        'electronic_signature' => env('FEATURE_MODULE_ELECTRONIC_SIGNATURE'),
        'intercompany' => env('FEATURE_MODULE_INTERCOMPANY'),
        'shift_planning' => env('FEATURE_MODULE_SHIFT_PLANNING'),
        'resource_planning' => env('FEATURE_MODULE_RESOURCE_PLANNING'),
        'italian_vat' => env('FEATURE_MODULE_ITALIAN_VAT'),
        'accounts_payable' => env('FEATURE_MODULE_ACCOUNTS_PAYABLE'),
        'liquidity_planning' => env('FEATURE_MODULE_LIQUIDITY_PLANNING'),
        'artisanal_gelato' => env('FEATURE_MODULE_ARTISANAL_GELATO'),
        'ai_cognitive' => env('FEATURE_MODULE_AI_COGNITIVE'),
        'data_residency' => env('FEATURE_MODULE_DATA_RESIDENCY'),
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
