<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Swiss payroll (Swissdec-style)
    |--------------------------------------------------------------------------
    |
    | Structural settings for the Swiss payroll engine of the HR module. Rates, thresholds and withholding
    | tax tariffs are NOT configured here: they live in the year-versioned `hr_ch_rate_sets` and
    | `hr_ch_withholding_tariffs` tables.
    |
    */

    // Wage type code used for the contractual monthly salary line generated for every payslip.
    'base_salary_wage_type' => env('PAYROLL_CH_BASE_SALARY_WAGE_TYPE', '1000'),

    // Storage disk and folder for generated ELM-style salary declaration XML files.
    'declaration_disk' => env('PAYROLL_CH_DECLARATION_DISK', 'private'),
    'declaration_directory' => 'payroll/ch/declarations',

    // ELM schema version the exporter targets (element naming follows Swissdec ELM 5.0).
    'elm_schema_version' => '5.0',
    'elm_namespace' => 'http://www.swissdec.ch/schema/sd/20200220/SalaryDeclaration',

    // Optional absolute path to the official ELM XSD. When the file exists, generated declarations are
    // validated against it; the official schemas are distributed by Swissdec and are not vendored.
    'elm_xsd_path' => env('PAYROLL_CH_ELM_XSD_PATH'),

];
