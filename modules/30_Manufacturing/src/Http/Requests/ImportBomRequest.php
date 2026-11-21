<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a BOM import (rows from a CAD, CSV or Excel file mapped to columns): a dry run reports every row, a
 * real run creates the BOM draft when all rows are valid.
 */
final class ImportBomRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_code' => ['required', 'string', 'max:50'],
            'name' => ['required_if:dry_run,false', 'nullable', 'string', 'max:200'],
            'dry_run' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.item_code' => ['nullable', 'string', 'max:50'],
            'rows.*.quantity_per' => ['nullable', 'string', 'max:30'],
            'rows.*.uom' => ['nullable', 'string', 'max:20'],
            'rows.*.scrap_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999'],
            'rows.*.yield_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'rows.*.fixed_scrap_quantity' => ['nullable', ...self::Quantity],
            'rows.*.operation_sequence' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
