<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryLandedCostDocument',
    title: 'Landed Cost Document',
    required: ['id', 'number', 'status', 'cost_type', 'allocation_key', 'currency', 'total_amount', 'document_date', 'receipts', 'allocations']
)]
class InventoryLandedCostDocumentSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'LC-2026-00001')]
    public string $number;

    #[OA\Property(enum: ['draft', 'applied', 'reversed'])]
    public string $status;

    #[OA\Property(enum: ['freight', 'customs_duty', 'insurance', 'brokerage', 'other'])]
    public string $cost_type;

    #[OA\Property(enum: ['value', 'weight', 'volume', 'quantity'])]
    public string $allocation_key;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(example: '300.00')]
    public string $total_amount;

    #[OA\Property(format: 'date')]
    public string $document_date;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $supplier_invoice_id;

    #[OA\Property(type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'receipt_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'receipt_number', type: 'string', nullable: true),
    ]))]
    public array $receipts;

    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLandedCostAllocation'))]
    public array $allocations;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $journal_entry_id;

    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $applied_at;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $reversal_of_id;

    #[OA\Property(type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'total_inventory_delta', type: 'string', example: '30.00'),
        new OA\Property(property: 'total_cogs_delta', type: 'string', example: '20.00'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ])]
    public ?array $revaluation;
}
