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
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'members', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'member_tenant_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'ownership_percentage', type: 'string', example: '80.0000'),
            new OA\Property(property: 'consolidation_method', type: 'string', enum: ['full', 'proportional', 'equity']),
            new OA\Property(property: 'is_parent', type: 'boolean'),
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
        new OA\Property(property: 'consolidation_method', description: 'full: line by line; equity: the investment is brought to the share of net assets; proportional is stored but refused by a run', type: 'string', enum: ['full', 'proportional', 'equity']),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerUpdateConsolidationMemberRequest',
    required: ['ownership_percentage', 'consolidation_method'],
    type: 'object',
    properties: [
        new OA\Property(property: 'ownership_percentage', type: 'number', example: 100),
        new OA\Property(property: 'consolidation_method', type: 'string', enum: ['full', 'proportional', 'equity']),
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
        new OA\Property(property: 'rows', description: 'One row per mapped pair: entity_a and entity_b (tenant, account, local and group amount in the natural sign), difference = A - B, tolerance, has_difference, status matched or difference', type: 'array', items: new OA\Items(type: 'object')),
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
final class GeneralLedgerConsolidationSchemas {}
