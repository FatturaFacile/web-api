<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock a work folder takes: reserved first, then issued when the part is fitted, or released again. The stock ids are
 * opaque references handed back by the stock gateway (the inventory module behind it), never foreign keys.
 *
 * @property string $id
 * @property string $folder_id
 * @property ?string $folder_line_id
 * @property string $product_id
 * @property string $quantity
 * @property string $status
 * @property ?string $stock_reservation_id
 * @property ?string $stock_movement_id
 */
final class WorkshopPartConsumption extends WorkshopRecord
{
    protected $table = 'workshop_part_consumptions';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'issued_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<WorkshopFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WorkshopFolder::class, 'folder_id');
    }
}
