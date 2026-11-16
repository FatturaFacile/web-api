<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use LogicException;

/**
 * Immutable, checksummed snapshot of the year-end inventory book (libro inventari) for audit retrieval.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property int $year
 * @property \Illuminate\Support\Carbon $as_of
 * @property string|null $warehouse_id
 * @property string $status
 * @property int $rows_count
 * @property string $total_quantity
 * @property string $total_value
 * @property string $gl_inventory_balance
 * @property string $difference
 * @property array<string, mixed>|null $method_breakdown
 * @property string $payload
 * @property string $checksum
 * @property string|null $generated_by
 * @property \Illuminate\Support\Carbon $generated_at
 */
final class InventoryBookExport extends BaseEnterpriseModel
{
    protected $table = 'inventory_book_exports';

    protected static function booted(): void
    {
        self::updating(static function (self $export): void {
            throw new LogicException('Inventory book exports are immutable.');
        });

        self::deleting(static function (self $export): void {
            throw new LogicException('Inventory book exports are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'as_of' => 'date',
            'rows_count' => 'integer',
            'total_quantity' => 'decimal:4',
            'total_value' => 'decimal:2',
            'gl_inventory_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'method_breakdown' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
