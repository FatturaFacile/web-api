<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas and tag of the consolidation endpoints. Amounts are decimal strings with 4 decimals, in group currency unless
 * stated otherwise. The accounting assumptions are documented in ConsolidationCalculator; no compliance with IFRS 10,
 * OIC 17 or the Swiss CO is claimed.
 */
#[OA\Tag(name: 'General Ledger Consolidation', description: 'Groups of legal entities (tenants), intercompany reconciliation, consolidation runs per fiscal period with eliminations kept apart from the ledgers of the entities, and consolidated statements')]
#[OA\Schema(
    schema: 'GeneralLedgerConsolidationGroupResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'group_currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'translation_reserve_code', type: 'string', example: 'CONS-CTA'),
        new OA\Property(property: 'consolidation_difference_code', type: 'string', example: 'CONS-DIFF'),
        new OA\Property(property: 'equity_method_result_code', type: 'string', example: 'CONS-EQM-PL'),
        new OA\Property(property: 'equity_method_reserve_code', type: 'string', example: 'CONS-EQM-RES'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'elimination_difference_policy', description: 'keep_difference (default): eliminate the smaller of two intercompany balances and report the difference; post_to_difference_account: eliminate both and post the residual to the difference account', type: 'string', enum: ['keep_difference', 'post_to_difference_account']),
        new OA\Property(property: 'goodwill_method', description: 'none (default), straight_line (needs goodwill_amortisation_years) or impairment_only. No legal period is assumed.', type: 'string', enum: ['none', 'straight_line', 'impairment_only']),
        new OA\Property(property: 'goodwill_amortisation_years', type: 'integer', minimum: 1, maximum: 100),
        new OA\Property(property: 'require_account_mapping', description: 'Refuse a run when an account with a balance is not mapped to the group chart', type: 'boolean'),
        new OA\Property(property: 'deferred_tax_on_unrealised_profit', description: 'Recognise deferred tax on the unrealised intragroup profit that is eliminated (OIC 17 para. 51). The rate comes from the tax_rate of the buying member or unrealised_profit_tax_rate; none is assumed and a run without a rate is refused', type: 'boolean', nullable: true),
        new OA\Property(property: 'unrealised_profit_tax_rate', description: 'Rate in percent used when the buying member has no tax_rate', type: 'number', nullable: true),
        new OA\Property(property: 'fair_value_first_year_rule', description: 'pro_rata (default): the release of a fair-value adjustment counts whole months from the acquisition date; full_year: from the start of the fiscal year of the acquisition', type: 'string', enum: ['pro_rata', 'full_year'], nullable: true),
        new OA\Property(property: 'negative_goodwill_treatment', description: 'consolidation_reserve (default, OIC 17 para. 71-72, with the provision of para. 73 when expected charges are entered on the acquisition) or immediate_income (the IFRS 3 treatment: income of the year of the acquisition)', type: 'string', enum: ['consolidation_reserve', 'immediate_income'], nullable: true),
        new OA\Property(property: 'goodwill_translation', description: 'historical (default): goodwill and fair value stay at the rate of the acquisition date; closing: their balance is retranslated at the closing rate of the period against the translation reserve (only for acquisitions entered in a local currency)', type: 'string', enum: ['historical', 'closing'], nullable: true),
        new OA\Property(property: 'synthetic_accounts', description: 'Role => code override: goodwill, goodwill_accumulated, goodwill_amortisation, goodwill_impairment, goodwill_prior, negative_goodwill, preacquisition_reserves, fair_value_reserve, deferred_tax, intercompany_difference, intercompany_pl_difference, prior_unrealised_profit', type: 'object'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'members', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'member_tenant_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'ownership_percentage', type: 'string', example: '80.0000'),
            new OA\Property(property: 'consolidation_method', type: 'string', enum: ['full', 'proportional', 'equity']),
            new OA\Property(property: 'is_parent', type: 'boolean'),
            new OA\Property(property: 'holder_tenant_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'fiscal_year_offset_months', type: 'integer'),
        ], type: 'object')),
        new OA\Property(property: 'relations', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerIntercompanyRelationResource')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreConsolidationGroupRequest',
    required: ['name', 'group_currency'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190, example: 'Gruppo Alfa'),
        new OA\Property(property: 'group_currency', description: 'Currency of the consolidated statements', type: 'string', minLength: 3, maxLength: 3, example: 'EUR'),
        new OA\Property(property: 'translation_reserve_code', description: 'Code of the synthetic equity account that takes the translation difference (default CONS-CTA)', type: 'string', maxLength: 40, nullable: true),
        new OA\Property(property: 'consolidation_difference_code', description: 'Code of the synthetic account of the difference between investment and equity (default CONS-DIFF)', type: 'string', maxLength: 40, nullable: true),
        new OA\Property(property: 'equity_method_result_code', description: 'Code of the account of the share of profit of equity-accounted entities (default CONS-EQM-PL)', type: 'string', maxLength: 40, nullable: true),
        new OA\Property(property: 'equity_method_reserve_code', description: 'Code of the equity-method reserve (default CONS-EQM-RES)', type: 'string', maxLength: 40, nullable: true),
        new OA\Property(property: 'elimination_difference_policy', description: 'keep_difference (default): eliminate the smaller of two intercompany balances and report the difference; post_to_difference_account: eliminate both and post the residual to the difference account', type: 'string', enum: ['keep_difference', 'post_to_difference_account'], nullable: true),
        new OA\Property(property: 'goodwill_method', description: 'none (default), straight_line (needs goodwill_amortisation_years) or impairment_only. No legal period is assumed.', type: 'string', enum: ['none', 'straight_line', 'impairment_only'], nullable: true),
        new OA\Property(property: 'goodwill_amortisation_years', type: 'integer', minimum: 1, maximum: 100, nullable: true),
        new OA\Property(property: 'require_account_mapping', description: 'Refuse a run when an account with a balance is not mapped to the group chart', type: 'boolean', nullable: true),
        new OA\Property(property: 'deferred_tax_on_unrealised_profit', description: 'Recognise deferred tax on the unrealised intragroup profit that is eliminated (OIC 17 para. 51). The rate comes from the tax_rate of the buying member or unrealised_profit_tax_rate; none is assumed and a run without a rate is refused', type: 'boolean', nullable: true),
        new OA\Property(property: 'unrealised_profit_tax_rate', description: 'Rate in percent used when the buying member has no tax_rate', type: 'number', nullable: true),
        new OA\Property(property: 'fair_value_first_year_rule', description: 'pro_rata (default): the release of a fair-value adjustment counts whole months from the acquisition date; full_year: from the start of the fiscal year of the acquisition', type: 'string', enum: ['pro_rata', 'full_year'], nullable: true),
        new OA\Property(property: 'negative_goodwill_treatment', description: 'consolidation_reserve (default, OIC 17 para. 71-72, with the provision of para. 73 when expected charges are entered on the acquisition) or immediate_income (the IFRS 3 treatment: income of the year of the acquisition)', type: 'string', enum: ['consolidation_reserve', 'immediate_income'], nullable: true),
        new OA\Property(property: 'goodwill_translation', description: 'historical (default): goodwill and fair value stay at the rate of the acquisition date; closing: their balance is retranslated at the closing rate of the period against the translation reserve (only for acquisitions entered in a local currency)', type: 'string', enum: ['historical', 'closing'], nullable: true),
        new OA\Property(property: 'synthetic_accounts', description: 'Role => code override: goodwill, goodwill_accumulated, goodwill_amortisation, goodwill_impairment, goodwill_prior, negative_goodwill, preacquisition_reserves, fair_value_reserve, deferred_tax, intercompany_difference, intercompany_pl_difference, prior_unrealised_profit', type: 'object', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerUpdateConsolidationGroupRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'group_currency', type: 'string', minLength: 3, maxLength: 3),
        new OA\Property(property: 'translation_reserve_code', type: 'string', maxLength: 40),
        new OA\Property(property: 'consolidation_difference_code', type: 'string', maxLength: 40),
        new OA\Property(property: 'equity_method_result_code', type: 'string', maxLength: 40),
        new OA\Property(property: 'equity_method_reserve_code', type: 'string', maxLength: 40),
        new OA\Property(property: 'elimination_difference_policy', description: 'keep_difference (default): eliminate the smaller of two intercompany balances and report the difference; post_to_difference_account: eliminate both and post the residual to the difference account', type: 'string', enum: ['keep_difference', 'post_to_difference_account'], nullable: true),
        new OA\Property(property: 'goodwill_method', description: 'none (default), straight_line (needs goodwill_amortisation_years) or impairment_only. No legal period is assumed.', type: 'string', enum: ['none', 'straight_line', 'impairment_only'], nullable: true),
        new OA\Property(property: 'goodwill_amortisation_years', type: 'integer', minimum: 1, maximum: 100, nullable: true),
        new OA\Property(property: 'require_account_mapping', description: 'Refuse a run when an account with a balance is not mapped to the group chart', type: 'boolean', nullable: true),
        new OA\Property(property: 'deferred_tax_on_unrealised_profit', description: 'Recognise deferred tax on the unrealised intragroup profit that is eliminated (OIC 17 para. 51). The rate comes from the tax_rate of the buying member or unrealised_profit_tax_rate; none is assumed and a run without a rate is refused', type: 'boolean', nullable: true),
        new OA\Property(property: 'unrealised_profit_tax_rate', description: 'Rate in percent used when the buying member has no tax_rate', type: 'number', nullable: true),
        new OA\Property(property: 'fair_value_first_year_rule', description: 'pro_rata (default): the release of a fair-value adjustment counts whole months from the acquisition date; full_year: from the start of the fiscal year of the acquisition', type: 'string', enum: ['pro_rata', 'full_year'], nullable: true),
        new OA\Property(property: 'negative_goodwill_treatment', description: 'consolidation_reserve (default, OIC 17 para. 71-72, with the provision of para. 73 when expected charges are entered on the acquisition) or immediate_income (the IFRS 3 treatment: income of the year of the acquisition)', type: 'string', enum: ['consolidation_reserve', 'immediate_income'], nullable: true),
        new OA\Property(property: 'goodwill_translation', description: 'historical (default): goodwill and fair value stay at the rate of the acquisition date; closing: their balance is retranslated at the closing rate of the period against the translation reserve (only for acquisitions entered in a local currency)', type: 'string', enum: ['historical', 'closing'], nullable: true),
        new OA\Property(property: 'synthetic_accounts', description: 'Role => code override: goodwill, goodwill_accumulated, goodwill_amortisation, goodwill_impairment, goodwill_prior, negative_goodwill, preacquisition_reserves, fair_value_reserve, deferred_tax, intercompany_difference, intercompany_pl_difference, prior_unrealised_profit', type: 'object', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreConsolidationMemberRequest',
    required: ['ownership_percentage', 'consolidation_method'],
    type: 'object',
    properties: [
        new OA\Property(property: 'member_tenant_id', description: 'The entity to add (required to add, not allowed when changing a member)', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ownership_percentage', description: 'Share of the entity owned by the group, above 0 and up to 100', type: 'number', example: 80),
        new OA\Property(property: 'consolidation_method', description: 'full: line by line; proportional: line by line at the effective ownership, no minority interests; equity: the investment is brought to the share of net assets', type: 'string', enum: ['full', 'proportional', 'equity']),
        new OA\Property(property: 'holder_tenant_id', description: 'The member that holds this one (indirect holding); null = the parent. The holder must be fully consolidated and the chain must not loop; effective ownership is the product of the direct percentages', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'fiscal_year_offset_months', description: 'Months between the start of the parent fiscal year and the one of the member, -11 to 11 (default 0)', type: 'integer', nullable: true),
        new OA\Property(property: 'consolidation_basis', description: 'own_year (default): income accounts from the start of the fiscal year of the member (see fiscal_year_offset_months); interim_twelve_months: income of the twelve months ending at the consolidation date, built from the ledger by date range (OIC 17 para. 40-42); cannot be combined with an offset', type: 'string', enum: ['own_year', 'interim_twelve_months'], nullable: true),
        new OA\Property(property: 'tax_rate', description: 'Tax rate in percent of the member, used for the deferred tax on unrealised intragroup profit when the member is the buyer', type: 'number', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerUpdateConsolidationMemberRequest',
    required: ['ownership_percentage', 'consolidation_method'],
    type: 'object',
    properties: [
        new OA\Property(property: 'ownership_percentage', type: 'number', example: 100),
        new OA\Property(property: 'consolidation_method', type: 'string', enum: ['full', 'proportional', 'equity']),
        new OA\Property(property: 'holder_tenant_id', description: 'The member that holds this one (indirect holding); null = the parent. The holder must be fully consolidated and the chain must not loop; effective ownership is the product of the direct percentages', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'fiscal_year_offset_months', description: 'Months between the start of the parent fiscal year and the one of the member, -11 to 11 (default 0)', type: 'integer', nullable: true),
        new OA\Property(property: 'consolidation_basis', description: 'own_year (default): income accounts from the start of the fiscal year of the member (see fiscal_year_offset_months); interim_twelve_months: income of the twelve months ending at the consolidation date, built from the ledger by date range (OIC 17 para. 40-42); cannot be combined with an offset', type: 'string', enum: ['own_year', 'interim_twelve_months'], nullable: true),
        new OA\Property(property: 'tax_rate', description: 'Tax rate in percent of the member, used for the deferred tax on unrealised intragroup profit when the member is the buyer', type: 'number', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreIntercompanyRelationRequest',
    required: ['entity_a_tenant_id', 'entity_b_tenant_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'entity_a_tenant_id', description: 'A member of the group; for investment mappings the investor', type: 'string', format: 'uuid'),
        new OA\Property(property: 'entity_b_tenant_id', description: 'Another member of the group; for investment mappings the investee', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tolerance_amount', description: 'Difference in group currency accepted as a match (default 0.01)', type: 'number', minimum: 0, nullable: true, example: 0.01),
        new OA\Property(property: 'description', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreIntercompanyMappingRequest',
    required: ['mapping_type', 'account_a_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'mapping_type', type: 'string', enum: ['receivable_payable', 'revenue_cost', 'investment_equity']),
        new OA\Property(property: 'account_a_id', description: 'Account of entity A (an asset for investment_equity)', type: 'string', format: 'uuid'),
        new OA\Property(property: 'account_b_id', description: 'Account of entity B (an equity account for investment_equity; may be omitted only for an investee on the equity method)', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerIntercompanyRelationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'group_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'entity_a_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'entity_b_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tolerance_amount', type: 'string', example: '0.0100'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'mappings', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'mapping_type', type: 'string', enum: ['receivable_payable', 'revenue_cost', 'investment_equity']),
            new OA\Property(property: 'account_a_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'account_b_id', type: 'string', format: 'uuid', nullable: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerRunConsolidationRequest',
    required: ['fiscal_period_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_period_id', description: 'Fiscal period of the parent entity (the active tenant)', type: 'string', format: 'uuid'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerConsolidationRunResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'group_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['completed', 'locked']),
        new OA\Property(property: 'group_currency', type: 'string'),
        new OA\Property(property: 'year_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'input_fingerprint', description: 'SHA-256 of the inputs of the run', type: 'string'),
        new OA\Property(property: 'members', description: 'Members with share and method at the time of the run', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'rates', description: 'Closing, average and historical rate used for each member', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'warnings', description: 'code and message of each warning: intercompany_difference, unclosed_prior_results, member_period_open, member_period_missing, investment_not_eliminated and others', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'totals', description: 'Key figures: adjustment_count, warning_count, total_assets, total_liabilities, total_equity, total_revenue, net_income, is_balanced flags and non_controlling_interests', type: 'object'),
        new OA\Property(property: 'locked_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'locked_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'adjustments', description: 'Eliminations and translation differences, each a set of debit and credit lines on account codes of the group', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerIntercompanyReconciliation',
    type: 'object',
    properties: [
        new OA\Property(property: 'group_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'currency', description: 'Group currency', type: 'string'),
        new OA\Property(property: 'rows', description: 'One row per mapped pair: entity_a and entity_b (tenant, account code of the group mapping, year_start of the entity under its fiscal year offset, local and group amount in the natural sign), difference = A - B, tolerance, has_difference, status matched or difference', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'summary', type: 'object', properties: [
            new OA\Property(property: 'pairs', type: 'integer'),
            new OA\Property(property: 'pairs_with_difference', type: 'integer'),
            new OA\Property(property: 'absolute_difference', description: 'Sum of absolute differences by mapping type', type: 'object'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerConsolidatedStatement',
    description: 'A consolidated statement of a run in group currency: header (run_id, version, status, currency, period_start, period_end) and the statement body. Trial balance: accounts with aggregated_net, adjustments_net, net, debit, credit, totals and is_balanced. Balance sheet: assets, liabilities, equity, result_of_the_period, totals, non_controlling_interests, is_balanced. Income statement: revenues, cogs, operating_expenses, net_income and its split between owners and non-controlling interests. Reclassified: rows of the scheme and unmapped_accounts.',
    type: 'object'
)]
#[OA\Schema(
    schema: 'GeneralLedgerGoodwillSchedule',
    description: 'Goodwill schedule of a run: goodwill_method, goodwill_amortisation_years and, per acquisition with a goodwill, member_tenant_id, name, acquisition_date, goodwill and rows per fiscal year of the parent (fiscal_year, period_start, period_end, opening_balance, amortisation, impairment, closing_balance, is_run_year and, for the year of the run, amortisation_to_period_end and impairment_to_period_end).',
    type: 'object'
)]
#[OA\Schema(
    schema: 'GeneralLedgerConsolidationInput',
    description: 'An input of the consolidation as stored (account mapping, acquisition with fair_value_adjustments and impairments, intragroup transaction, intragroup dividend). Amounts are decimal strings in group currency.',
    type: 'object'
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreConsolidationAccountMappingRequest',
    required: ['member_tenant_id', 'account_id', 'group_account_code'],
    type: 'object',
    properties: [
        new OA\Property(property: 'member_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'account_id', description: 'Account of the chart of the member', type: 'string', format: 'uuid'),
        new OA\Property(property: 'group_account_code', type: 'string', maxLength: 40, example: '4000'),
        new OA\Property(property: 'group_account_name', type: 'string', maxLength: 255, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreConsolidationAcquisitionRequest',
    required: ['member_tenant_id', 'acquisition_date', 'net_assets_at_acquisition'],
    type: 'object',
    properties: [
        new OA\Property(property: 'member_tenant_id', description: 'The acquired member (not the parent)', type: 'string', format: 'uuid'),
        new OA\Property(property: 'acquisition_date', type: 'string', format: 'date'),
        new OA\Property(property: 'cost', description: 'Cost of the investment in group currency; when omitted the balance of the mapped investment accounts', type: 'number', nullable: true),
        new OA\Property(property: 'net_assets_at_acquisition', description: 'Book net assets of the member at the acquisition date, group currency', type: 'number'),
        new OA\Property(property: 'acquired_percentage', description: 'Percentage bought; when omitted the direct ownership of the member', type: 'number', nullable: true),
        new OA\Property(property: 'expected_future_charges', description: 'Negative difference only: the part of it that corresponds to expected unfavourable results, in the input currency; it becomes a consolidation provision for future risks and charges (OIC 17 para. 73), the rest a consolidation reserve. Needs provision_release_years', type: 'number', nullable: true),
        new OA\Property(property: 'provision_release_years', description: 'Years over which the provision is used, straight-line by whole months from the acquisition date, whether or not the losses occur (para. 73)', type: 'integer', nullable: true),
        new OA\Property(property: 'input_currency', description: 'Currency of cost, net assets and fair-value amounts when not the group currency: they are translated at the rate of the acquisition date (impairments are always in group currency)', type: 'string', nullable: true),
        new OA\Property(property: 'recognise_deferred_tax', description: 'Whether deferred tax is recognised on the fair-value adjustments', type: 'boolean', default: false),
        new OA\Property(property: 'deferred_tax_rate', description: 'Rate in percent applied to the net fair-value adjustments; required when recognise_deferred_tax is true, no rate is assumed', type: 'number', nullable: true),
        new OA\Property(property: 'fair_value_adjustments', type: 'array', items: new OA\Items(required: ['account_code', 'account_type', 'amount'], properties: [
            new OA\Property(property: 'account_code', description: 'Account of the group chart', type: 'string'),
            new OA\Property(property: 'account_name', type: 'string', nullable: true),
            new OA\Property(property: 'account_type', type: 'string', enum: ['asset', 'liability']),
            new OA\Property(property: 'amount', description: 'Increase of the balance on the normal side of the account at 100 %; the group recognises the percentage acquired (OIC 17 para. 99)', type: 'number'),
            new OA\Property(property: 'useful_life_years', description: 'Years over which the recognised amount is released straight-line by whole months; none = not released (land, for instance)', type: 'integer', nullable: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreGoodwillImpairmentRequest',
    required: ['fiscal_year', 'amount'],
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_year', description: 'Year of the start of the fiscal year of the parent', type: 'integer', example: 2026),
        new OA\Property(property: 'amount', description: 'Impairment charged in that year, group currency', type: 'number', example: 1000),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreIntragroupTransactionRequest',
    required: ['seller_tenant_id', 'buyer_tenant_id', 'asset_type', 'transaction_date', 'asset_account_id', 'profit_account_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'relation_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'seller_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'buyer_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'asset_type', type: 'string', enum: ['inventory', 'fixed_asset']),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date'),
        new OA\Property(property: 'sale_price', type: 'number', nullable: true),
        new OA\Property(property: 'seller_cost', description: 'Book cost of the seller; margin = sale_price - seller_cost', type: 'number', nullable: true),
        new OA\Property(property: 'margin_amount', description: 'The margin directly (wins over price and cost)', type: 'number', nullable: true),
        new OA\Property(property: 'remaining_percentage', description: 'Share still in stock at the period end (inventory); default 100', type: 'number', nullable: true),
        new OA\Property(property: 'opening_remaining_percentage', description: 'Share in stock at the start of the fiscal year; default the same as remaining_percentage', type: 'number', nullable: true),
        new OA\Property(property: 'useful_life_years', description: 'Required for a fixed asset: the margin is released over it by whole months', type: 'integer', nullable: true),
        new OA\Property(property: 'asset_account_id', description: 'Asset account of the buyer that holds the goods', type: 'string', format: 'uuid'),
        new OA\Property(property: 'profit_account_id', description: 'Income account of the seller charged with the elimination', type: 'string', format: 'uuid'),
        new OA\Property(property: 'depreciation_account_id', description: 'Depreciation expense account of the buyer (fixed assets)', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreIntragroupDividendRequest',
    required: ['payer_tenant_id', 'receiver_tenant_id', 'dividend_date', 'amount', 'income_account_id', 'equity_account_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'payer_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'receiver_tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'dividend_date', type: 'string', format: 'date'),
        new OA\Property(property: 'amount', description: 'Amount received by the receiver, group currency', type: 'number'),
        new OA\Property(property: 'income_account_id', description: 'Revenue account of the receiver that holds the dividend', type: 'string', format: 'uuid'),
        new OA\Property(property: 'equity_account_id', description: 'Equity account of the payer the dividend was charged to', type: 'string', format: 'uuid'),
    ]
)]
final class GeneralLedgerConsolidationSchemas {}
