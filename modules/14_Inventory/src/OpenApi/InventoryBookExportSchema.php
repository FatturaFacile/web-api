<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryBookExport',
    title: 'Inventory Book Export',
    description: 'Immutable, SHA-256 checksummed inventory book snapshot',
    required: ['id', 'year', 'as_of', 'checksum']
)]
class InventoryBookExportSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 2026)]
    public int $year;

    #[OA\Property(format: 'date')]
    public string $as_of;

    #[OA\Property(example: 42)]
    public int $rows_count;

    #[OA\Property(type: 'object')]
    public array $totals;

    #[OA\Property(type: 'object')]
    public array $reconciliation;

    #[OA\Property(example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')]
    public string $checksum;

    #[OA\Property(format: 'date-time')]
    public string $generated_at;
}
