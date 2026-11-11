<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Frozen picture of the global data of the schema, checked by the architecture tests.
 *
 * Owner policy: every table has a nullable tenant_id (the exclusions are in App\Services\Tenancy\TenantSchemaPolicy).
 * A null tenant_id means "visible to every tenant" and is legitimate only for the tables below: shared data (lookups,
 * translations, catalogues, legal reference data, platform data). The model of such a table declares it with
 * AllowsGlobalRecords. A model that declares it without its table being listed here fails the architecture test until
 * the owner decides that its rows can be global; for every other model a null tenant_id is invisible and unwritable.
 */
final class TenantSchemaBaseline
{
    /**
     * Tables whose model allows global records (AllowsGlobalRecords).
     *
     * @var list<string>
     */
    public const GLOBAL_TABLES = [
        'approval_status_translations',
        'approval_statuses',
        'assoc_disciplines',
        'assoc_expense_categories',
        'assoc_legal_parameters',
        'assoc_registry_types',
        'assoc_social_roles',
        'contact_categories',
        'contact_category_translations',
        'contact_type_translations',
        'contact_types',
        'countries',
        'country_translations',
        'ct_price_items',
        'ct_price_lists',
        'ct_work_cycle_steps',
        'ct_work_cycles',
        'currencies',
        'currency_translations',
        'customs_countries',
        'customs_document_types',
        'customs_packaging_types',
        'customs_statistical_keys',
        'customs_tariff_numbers',
        'document_status_translations',
        'document_statuses',
        'enasarco_parameters',
        'exchange_rates',
        'expense_categories',
        'expense_category_translations',
        'hr_shift_types',
        'inventory_movement_type_translations',
        'inventory_movement_types',
        'lookup_categories',
        'lookup_item_translations',
        'lookup_items',
        'notification_type_translations',
        'notification_types',
        'password_histories',
        'payment_method_translations',
        'payment_methods',
        'payment_term_translations',
        'payment_terms',
        'payroll_annual_parameters',
        'payroll_ccl_construction_parameters',
        'payroll_collective_agreement_levels',
        'payroll_collective_agreements',
        'payroll_family_allowance_rates',
        'payroll_legal_parameters',
        'payroll_wage_types',
        'payroll_withholding_tax_tariffs',
        'product_charge_types',
        'project_status_translations',
        'project_statuses',
        'region_translations',
        'regions',
        'shipping_carriers',
        'task_status_translations',
        'task_statuses',
        'time_public_holidays',
        'translations',
        'units_of_measure',
        'vat_code_translations',
        'vat_codes',
        'workflow_state_translations',
        'workflow_states',
    ];

    /**
     * Tables the owner may want to decide: their rows are tenant data today (no model allows null for them), but a
     * shared catalogue version could be useful. Nothing is global until the model declares it.
     *
     * @var array<string, string>
     */
    public const UNDECIDED_TABLES = [
        'chart_of_accounts' => 'a standard chart (e.g. the Swiss KMU or the Italian plan) could be a shared template; today every tenant owns its accounts',
        'product_brands' => 'brand catalogue: per tenant today',
        'product_categories' => 'category tree: per tenant today',
        'document_templates' => 'default document templates could be shared; per tenant today',
        'tenant_user' => 'membership pivot: has a nullable tenant_id but the rows always belong to a tenant',
        'tenant_access_requests' => 'platform onboarding data read by the platform administrator: no tenant exists yet when it is written',
        'enterprise_feature_flags' => 'flags resolve through FeatureManager (tenant, user, global lookup): null already means global there',
    ];
}
