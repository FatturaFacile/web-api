<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a work folder: labour, a part, paint material, sublet work.
 *
 * @property string $folder_id
 * @property string $line_type
 * @property ?string $product_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_percentage
 * @property string $tax_rate
 * @property string $line_total
 */
final class WorkshopFolderLine extends WorkshopRecord
{
    /** @var list<string> */
    public const array Types = ['labour', 'part', 'paint', 'sublet', 'other'];

    protected $table = 'workshop_folder_lines';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_percentage' => 'decimal:2', 'tax_rate' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<WorkshopFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WorkshopFolder::class, 'folder_id');
    }
}
