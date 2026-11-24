<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryLandedCostInput',
    title: 'Landed Cost Document Input',
    required: ['document_date', 'cost_type', 'allocation_key', 'currency', 'total_amount']
)]
class InventoryLandedCostInputSchema
{
    #[OA\Property(format: 'date', example: '2026-11-15')]
    public string $document_date;

    #[OA\Property(enum: ['freight', 'customs_duty', 'insurance', 'brokerage', 'other'])]
    public string $cost_type;

    #[OA\Property(enum: ['value', 'weight', 'volume', 'quantity'])]
    public string $allocation_key;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(example: 300.00)]
    public float $total_amount;

    #[OA\Property(description: 'Optional rate to company currency (default 1)', example: 1)]
    public ?float $exchange_rate;

    #[OA\Property(format: 'uuid', nullable: true, description: 'Passive invoice reference (Mod 048)')]
    public ?string $supplier_invoice_id;

    #[OA\Property(nullable: true)]
    public ?string $notes;

    #[OA\Property(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $receipt_ids;

    #[OA\Property(type: 'array', description: 'Alternative to receipt_ids: allocate to everything received against these purchase orders', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $purchase_order_ids;
}
