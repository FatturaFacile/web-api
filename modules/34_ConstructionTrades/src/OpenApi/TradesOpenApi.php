<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, payloads and responses shared by the construction trades endpoints.
 */
#[OA\Tag(name: 'Construction Trades', description: 'Painters, plasterers and builders: price lists, work cycles, bids, settlements, billings and site costs')]
#[OA\Response(response: 'TradesUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TradesForbidden', description: 'The role of the user in the company does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TradesNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TradesUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'TradesDescriptions', description: 'Text per language', type: 'object', properties: [
    new OA\Property(property: 'de', type: 'string'), new OA\Property(property: 'fr', type: 'string'), new OA\Property(property: 'it', type: 'string'), new OA\Property(property: 'en', type: 'string'),
])]
#[OA\Schema(schema: 'TradesPriceListRequest', type: 'object', required: ['name', 'source', 'currency', 'languages'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'source', type: 'string', enum: ['own', 'npk', 'crb', 'asip', 'other']),
    new OA\Property(property: 'edition', type: 'string', nullable: true),
    new OA\Property(property: 'license_reference', description: 'Required for npk, crb and asip: the licence the catalogue is used under', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'string', enum: ['de', 'fr', 'it', 'en'])),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'TradesPriceItemRequest', type: 'object', required: ['position_code', 'unit', 'unit_price', 'descriptions'], properties: [
    new OA\Property(property: 'position_code', type: 'string'),
    new OA\Property(property: 'parent_code', type: 'string', nullable: true),
    new OA\Property(property: 'unit', type: 'string', example: 'm2'),
    new OA\Property(property: 'unit_price', type: 'string'),
    new OA\Property(property: 'labour_minutes', description: 'Per unit', type: 'string', nullable: true),
    new OA\Property(property: 'material_cost', description: 'Per unit', type: 'string', nullable: true),
    new OA\Property(property: 'machine_cost', description: 'Per unit', type: 'string', nullable: true),
    new OA\Property(property: 'descriptions', ref: '#/components/schemas/TradesDescriptions'),
])]
#[OA\Schema(schema: 'TradesImportRequest', type: 'object', required: ['file'], properties: [
    new OA\Property(property: 'file', description: 'CSV with the columns position_code, unit, unit_price and optionally parent_code, labour_minutes, material_cost, machine_cost, description_de/fr/it/en', type: 'string', format: 'binary'),
])]
#[OA\Schema(schema: 'TradesImportResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'created', type: 'integer'),
        new OA\Property(property: 'updated', type: 'integer'),
        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'line', type: 'integer'), new OA\Property(property: 'message', type: 'string')])),
    ]),
])]
#[OA\Schema(schema: 'TradesWorkCycleRequest', type: 'object', required: ['name', 'unit', 'descriptions', 'steps'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'descriptions', ref: '#/components/schemas/TradesDescriptions'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object', required: ['descriptions', 'unit_price'], properties: [
        new OA\Property(property: 'price_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'descriptions', ref: '#/components/schemas/TradesDescriptions'),
        new OA\Property(property: 'quantity_factor', type: 'string'),
        new OA\Property(property: 'unit_price', type: 'string'),
        new OA\Property(property: 'labour_minutes', type: 'string'),
        new OA\Property(property: 'material_cost', type: 'string'),
        new OA\Property(property: 'machine_cost', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'TradesBidRequest', type: 'object', required: ['contact_id', 'title', 'language', 'currency', 'tax_rate'], properties: [
    new OA\Property(property: 'construction_site_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'language', type: 'string', enum: ['de', 'fr', 'it', 'en']),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'tax_rate', description: 'Entered by the user', type: 'string'),
    new OA\Property(property: 'discount_percentage', type: 'string'),
    new OA\Property(property: 'retention_percentage', description: 'Withheld on partial billings, released by the final account', type: 'string'),
    new OA\Property(property: 'labour_hour_rate', description: 'Cost rate for the labour budget', type: 'string'),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TradesPositionRequest', type: 'object', required: ['quantity'], properties: [
    new OA\Property(property: 'price_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'work_cycle_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'position_code', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'unit_price', type: 'string'),
    new OA\Property(property: 'labour_minutes', type: 'string'),
    new OA\Property(property: 'material_cost', type: 'string'),
    new OA\Property(property: 'machine_cost', type: 'string'),
])]
#[OA\Schema(schema: 'TradesStatusRequest', type: 'object', required: ['status'], properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'accepted', 'rejected']),
])]
#[OA\Schema(schema: 'TradesMeasurementRequest', type: 'object', required: ['bid_position_id', 'measured_on', 'quantity'], properties: [
    new OA\Property(property: 'bid_position_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'measured_on', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', description: 'Negative to correct', type: 'string'),
    new OA\Property(property: 'note', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TradesBillingRequest', type: 'object', required: ['kind', 'date'], properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['partial', 'final']),
    new OA\Property(property: 'basis', description: 'Partial only', type: 'string', enum: ['percentage', 'measured']),
    new OA\Property(property: 'percentage', description: 'Progress to date, in percent of the bid', type: 'string'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'TradesSiteCostRequest', type: 'object', required: ['cost_type', 'cost_date', 'description', 'quantity', 'unit_cost'], properties: [
    new OA\Property(property: 'bid_position_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'cost_type', type: 'string', enum: ['labour', 'material', 'machine', 'other']),
    new OA\Property(property: 'cost_date', type: 'string', format: 'date'),
    new OA\Property(property: 'worker_user_id', description: 'Required for labour', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'quantity', description: 'Hours for labour', type: 'string'),
    new OA\Property(property: 'unit_cost', type: 'string'),
])]
#[OA\Schema(schema: 'TradesRecordResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_generic', description: 'Shared by every company and read-only', type: 'boolean'),
    ]),
])]
#[OA\Schema(schema: 'TradesRecordCollection', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'links', type: 'object'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'TradesSettlementResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'bid_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'gross', type: 'string'),
        new OA\Property(property: 'discount', type: 'string'),
        new OA\Property(property: 'net', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'TradesBudgetResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'unassigned_costs', type: 'string'),
        new OA\Property(property: 'totals', type: 'object'),
    ]),
])]
final class TradesOpenApi {}
