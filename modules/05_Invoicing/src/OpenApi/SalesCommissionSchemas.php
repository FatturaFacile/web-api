<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of sales targets and commission statements.
 */
#[OA\Schema(schema: 'InvoicingSaveSalesTeamRequest', description: 'Request payload validated by SaveSalesTeamRequest', required: ['name'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'sales_representative_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
])]
#[OA\Schema(schema: 'InvoicingSaveSalesTargetRequest', description: 'Request payload validated by SaveSalesTargetRequest: an agent or a team', required: ['period_start', 'period_end', 'target_amount', 'currency'], type: 'object', properties: [
    new OA\Property(property: 'sales_representative_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sales_team_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'target_amount', type: 'number'),
    new OA\Property(property: 'currency', type: 'string', maxLength: 3),
])]
#[OA\Schema(schema: 'InvoicingCommissionPeriodRequest', description: 'Request payload validated by CommissionPeriodRequest', required: ['from', 'to'], type: 'object', properties: [
    new OA\Property(property: 'from', type: 'string', format: 'date'),
    new OA\Property(property: 'to', type: 'string', format: 'date'),
    new OA\Property(property: 'sales_representative_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'InvoicingSaveCommissionSettingsRequest', description: 'Request payload validated by SaveCommissionSettingsRequest', required: ['expense_account_id', 'payable_account_id'], type: 'object', properties: [
    new OA\Property(property: 'expense_account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'payable_account_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'InvoicingSalesTargetAttainment', description: 'Targets against net sales', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'owner_type', type: 'string', enum: ['agent', 'team']),
        new OA\Property(property: 'owner_name', type: 'string'),
        new OA\Property(property: 'target_amount', type: 'string'),
        new OA\Property(property: 'actual_amount', type: 'string'),
        new OA\Property(property: 'attainment_percent', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'InvoicingCommissionStatement', description: 'A commission statement', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sales_representative_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'total_base', type: 'string'),
        new OA\Property(property: 'total_commission', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'paid']),
        new OA\Property(property: 'ledger_status', type: 'string', enum: ['not_posted', 'pending', 'posted']),
        new OA\Property(property: 'ledger_error', type: 'string', nullable: true),
    ]),
])]
final class SalesCommissionSchemas {}
