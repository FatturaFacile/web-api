<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the lease accounting endpoints. Amounts are decimal strings (two decimals), rates
 * are percentages as decimal strings (six decimals).
 */
#[OA\Tag(name: 'Leases', description: 'Lease accounting of the lessee (IFRS 16, OIC, Swiss GAAP FER): lease contracts, initial measurement of the lease liability and the right-of-use asset, monthly schedules, remeasurement versions, idempotent period postings to the general ledger, maturity analysis for the disclosures')]
#[OA\Response(response: 'LeasesUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'LeasesForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'LeasesNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'LeasesUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'LeaseContractRequest', required: ['title', 'lessor_name', 'asset_class', 'currency', 'commencement_date', 'term_months', 'payment_amount', 'incremental_borrowing_rate_percent'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'lessor_name', type: 'string', maxLength: 190),
    new OA\Property(property: 'lessor_contact_id', description: 'Lessor as a contact of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'asset_class', type: 'string', enum: ['real_estate', 'vehicles', 'machinery', 'it_equipment', 'other']),
    new OA\Property(property: 'asset_description', type: 'string', nullable: true),
    new OA\Property(property: 'fixed_asset_id', description: 'Fixed asset of Asset Management the lease relates to, by id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'accounting_standard', type: 'string', enum: ['ifrs16', 'oic', 'swiss_gaap_fer'], description: 'Defaults to ifrs16'),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'commencement_date', type: 'string', format: 'date'),
    new OA\Property(property: 'term_months', type: 'integer', minimum: 1, description: 'Non-cancellable term in months; with the extension reasonably certain, a whole number of payment periods'),
    new OA\Property(property: 'payment_amount', type: 'string', example: '1500.00', description: 'Amount of each payment'),
    new OA\Property(property: 'payment_frequency', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'annual'], description: 'Defaults to monthly'),
    new OA\Property(property: 'payment_timing', type: 'string', enum: ['advance', 'arrears'], description: 'Start or end of the period; defaults to arrears'),
    new OA\Property(property: 'escalation_type', type: 'string', enum: ['none', 'fixed', 'index'], description: 'fixed: a known annual step-up included in the measurement; index: indexation measured when the index changes (remeasurement)'),
    new OA\Property(property: 'escalation_rate_percent', type: 'string', nullable: true, example: '2.000000', description: 'Annual step-up for the escalation type fixed'),
    new OA\Property(property: 'incremental_borrowing_rate_percent', type: 'string', example: '4.500000', description: 'Annual rate (nominal, compounded monthly)'),
    new OA\Property(property: 'initial_direct_costs', type: 'string', description: 'Added to the right-of-use asset'),
    new OA\Property(property: 'incentives_received', type: 'string', description: 'Deducted from the right-of-use asset'),
    new OA\Property(property: 'purchase_option_price', type: 'string', nullable: true),
    new OA\Property(property: 'purchase_option_reasonably_certain', type: 'boolean', description: 'When true the price is paid with the last period, the asset is depreciated over its useful life, and useful_life_months is required'),
    new OA\Property(property: 'extension_months', type: 'integer', nullable: true),
    new OA\Property(property: 'extension_reasonably_certain', type: 'boolean', description: 'When true the extension months are part of the lease term'),
    new OA\Property(property: 'useful_life_months', type: 'integer', nullable: true),
    new OA\Property(property: 'exemption', type: 'string', enum: ['none', 'short_term', 'low_value'], description: 'short_term: 12 months or less without a purchase option reasonably certain; low_value: asset worth the threshold of the currency or less when new (underlying_asset_value). An exempt lease is expensed and has no schedule'),
    new OA\Property(property: 'underlying_asset_value', type: 'string', nullable: true),
    new OA\Property(property: 'rou_asset_account_id', description: 'Ledger account; defaults to 1670, created on first use', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'accumulated_depreciation_account_id', description: 'Defaults to 1675', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lease_liability_account_id', description: 'Defaults to 2450', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'interest_expense_account_id', description: 'Defaults to 6850', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'depreciation_expense_account_id', description: 'Defaults to 6410', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'LeaseActivateRequest', type: 'object', properties: [new OA\Property(property: 'post_recognition', type: 'boolean', description: 'Post the recognition journal at the commencement date; defaults to true')])]
#[OA\Schema(schema: 'LeaseRemeasureRequest', required: ['effective_date', 'reason'], type: 'object', properties: [
    new OA\Property(property: 'effective_date', type: 'string', format: 'date', description: 'First day of a month of the schedule that is not posted yet'),
    new OA\Property(property: 'reason', type: 'string', enum: ['modification', 'index_change', 'reassessment']),
    new OA\Property(property: 'new_payment_amount', type: 'string', nullable: true, description: 'Payment in force from the effective date; required for index_change, otherwise the payment of the schedule in force continues'),
    new OA\Property(property: 'remaining_term_months', type: 'integer', nullable: true, description: 'Months left from the effective date; defaults to the current remaining term; not changeable for index_change'),
    new OA\Property(property: 'new_discount_rate_percent', type: 'string', nullable: true, description: 'Revised rate; required for modification and reassessment, refused for index_change'),
    new OA\Property(property: 'post_journal', type: 'boolean', description: 'Post the remeasurement journal at the effective date; defaults to true'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'LeaseCancelRequest', required: ['reason'], type: 'object', properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)])]
#[OA\Schema(schema: 'LeasePostPeriodsRequest', required: ['through_date'], type: 'object', properties: [new OA\Property(property: 'through_date', type: 'string', format: 'date', description: 'Periods that ended on or before this date are posted; not after today')])]
#[OA\Schema(schema: 'LeaseScheduleVersion', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lease_contract_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version_number', type: 'integer'),
    new OA\Property(property: 'reason', type: 'string', enum: ['initial', 'modification', 'index_change', 'reassessment']),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date'),
    new OA\Property(property: 'start_period_number', type: 'integer'),
    new OA\Property(property: 'end_period_number', type: 'integer'),
    new OA\Property(property: 'discount_rate_percent', type: 'string'),
    new OA\Property(property: 'payment_amount', type: 'string'),
    new OA\Property(property: 'liability_before', type: 'string'),
    new OA\Property(property: 'liability_after', type: 'string'),
    new OA\Property(property: 'rou_before', type: 'string'),
    new OA\Property(property: 'rou_after', type: 'string'),
    new OA\Property(property: 'liability_adjustment', type: 'string'),
    new OA\Property(property: 'rou_adjustment', type: 'string'),
    new OA\Property(property: 'result_amount', type: 'string', description: 'Gain recognised when a reduction exceeds the right-of-use asset'),
    new OA\Property(property: 'total_undiscounted', type: 'string'),
    new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['current', 'superseded']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'LeaseContract', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', example: 'LS-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'lessor_name', type: 'string'),
    new OA\Property(property: 'lessor_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'asset_class', type: 'string', enum: ['real_estate', 'vehicles', 'machinery', 'it_equipment', 'other']),
    new OA\Property(property: 'asset_description', type: 'string', nullable: true),
    new OA\Property(property: 'fixed_asset_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'accounting_standard', type: 'string', enum: ['ifrs16', 'oic', 'swiss_gaap_fer']),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'commencement_date', type: 'string', format: 'date'),
    new OA\Property(property: 'term_months', type: 'integer'),
    new OA\Property(property: 'payment_amount', type: 'string'),
    new OA\Property(property: 'payment_frequency', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'annual']),
    new OA\Property(property: 'payment_timing', type: 'string', enum: ['advance', 'arrears']),
    new OA\Property(property: 'escalation_type', type: 'string', enum: ['none', 'fixed', 'index']),
    new OA\Property(property: 'escalation_rate_percent', type: 'string'),
    new OA\Property(property: 'incremental_borrowing_rate_percent', type: 'string'),
    new OA\Property(property: 'initial_direct_costs', type: 'string'),
    new OA\Property(property: 'incentives_received', type: 'string'),
    new OA\Property(property: 'purchase_option_price', type: 'string', nullable: true),
    new OA\Property(property: 'purchase_option_reasonably_certain', type: 'boolean'),
    new OA\Property(property: 'extension_months', type: 'integer', nullable: true),
    new OA\Property(property: 'extension_reasonably_certain', type: 'boolean'),
    new OA\Property(property: 'useful_life_months', type: 'integer', nullable: true),
    new OA\Property(property: 'exemption', type: 'string', enum: ['none', 'short_term', 'low_value']),
    new OA\Property(property: 'underlying_asset_value', type: 'string', nullable: true),
    new OA\Property(property: 'rou_asset_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'accumulated_depreciation_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lease_liability_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'interest_expense_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'depreciation_expense_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'expired', 'cancelled']),
    new OA\Property(property: 'current_version_number', type: 'integer'),
    new OA\Property(property: 'effective_term_months', type: 'integer', description: 'Term plus the extension reasonably certain to be exercised'),
    new OA\Property(property: 'activated_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'versions', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeaseScheduleVersion'), description: 'Only on the single lease'),
])]
#[OA\Schema(schema: 'LeaseScheduleLine', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'schedule_version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'period_number', type: 'integer'),
    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'opening_liability', type: 'string'),
    new OA\Property(property: 'payment', type: 'string'),
    new OA\Property(property: 'interest', type: 'string'),
    new OA\Property(property: 'principal', type: 'string', description: 'Payment minus interest'),
    new OA\Property(property: 'closing_liability', type: 'string'),
    new OA\Property(property: 'rou_opening', type: 'string'),
    new OA\Property(property: 'depreciation', type: 'string'),
    new OA\Property(property: 'rou_closing', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'posted', 'superseded']),
])]
#[OA\Schema(schema: 'LeaseMeasurementPreview', type: 'object', properties: [
    new OA\Property(property: 'discount_rate_percent', type: 'string'),
    new OA\Property(property: 'payment_amount', type: 'string'),
    new OA\Property(property: 'lease_liability', type: 'string'),
    new OA\Property(property: 'rou_asset', type: 'string'),
    new OA\Property(property: 'total_undiscounted', type: 'string'),
    new OA\Property(property: 'end_period_number', type: 'integer'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'LeasePeriodPosting', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lease_contract_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'period_number', type: 'integer'),
    new OA\Property(property: 'posting_date', type: 'string', format: 'date'),
    new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'interest', type: 'string'),
    new OA\Property(property: 'depreciation', type: 'string'),
    new OA\Property(property: 'payment', type: 'string'),
])]
#[OA\Schema(schema: 'LeasePostingRunResult', type: 'object', properties: [
    new OA\Property(property: 'contract_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string'),
    new OA\Property(property: 'posted_periods', type: 'integer'),
    new OA\Property(property: 'error', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'LeaseMaturityRow', type: 'object', properties: [
    new OA\Property(property: 'contract_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'lessor_name', type: 'string'),
    new OA\Property(property: 'asset_class', type: 'string'),
    new OA\Property(property: 'accounting_standard', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'within_one_year', type: 'string'),
    new OA\Property(property: 'one_to_five_years', type: 'string'),
    new OA\Property(property: 'over_five_years', type: 'string'),
    new OA\Property(property: 'total_undiscounted', type: 'string'),
    new OA\Property(property: 'total_discounted', type: 'string', description: 'The lease liability at the reporting date'),
    new OA\Property(property: 'unearned_interest', type: 'string'),
    new OA\Property(property: 'current_liability', type: 'string'),
    new OA\Property(property: 'non_current_liability', type: 'string'),
    new OA\Property(property: 'rou_carrying_amount', type: 'string'),
])]
#[OA\Schema(schema: 'LeaseMaturitySection', type: 'object', properties: [
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'leases', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeaseMaturityRow')),
    new OA\Property(property: 'totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'exempt_commitments', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Remaining payments of the leases with an exemption, in the same three buckets'),
])]
#[OA\Schema(schema: 'LeaseMaturityReport', type: 'object', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'currencies', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeaseMaturitySection')),
])]
final class LeaseAccountingOpenApi {}
