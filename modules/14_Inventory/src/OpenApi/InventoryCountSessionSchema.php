<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response schemas of the valuation engine, landed costs and inventory book (cost layers, valuation configuration,
 * barcode scan and count session records).
 */
#[OA\Schema(
    schema: 'InventoryCountSession',
    title: 'Cycle Count Session',
    description: 'Physical counting audit session for warehouse locations and bins',
    required: ['id', 'warehouse_id', 'status', 'start_date']
)]
class InventoryCountSessionSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 'in_progress', enum: ['draft', 'in_progress', 'completed', 'cancelled'])]
    public string $status;

    #[OA\Property(example: '2026-10-28T08:00:00Z')]
    public string $start_date;

    #[OA\Property(example: 2)]
    public int $discrepancies_count;
}
