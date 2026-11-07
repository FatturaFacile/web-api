<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ProcurementPriceCheck', properties: [
    new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tolerance_percent', type: 'string'),
    new OA\Property(property: 'has_differences', type: 'boolean'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'code', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'quantity', type: 'string', nullable: true),
        new OA\Property(property: 'invoiced_unit_price', type: 'string'),
        new OA\Property(property: 'purchase_order_line_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'ordered_unit_price', type: 'string', nullable: true),
        new OA\Property(property: 'list_unit_price', type: 'string', nullable: true),
        new OA\Property(property: 'variance_amount', type: 'string', nullable: true),
        new OA\Property(property: 'variance_percent', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'above_order', 'below_order', 'above_list', 'unmatched']),
    ])),
])]
class PurchasePriceCheckSchemas {}
