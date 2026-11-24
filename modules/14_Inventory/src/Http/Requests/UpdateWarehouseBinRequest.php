<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\WarehouseBin;

/**
 * Changes to the master data of a bin: its type, capacities, lock and picking position. Location and barcode are
 * identity and do not change here.
 */
final class UpdateWarehouseBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bin_type' => ['sometimes', 'string', Rule::in(WarehouseBin::BIN_TYPES)],
            'max_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'max_weight_kg' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'max_volume_cbm' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'is_locked' => ['sometimes', 'boolean'],
            'pick_sequence' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'block_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'coord_x' => ['sometimes', 'nullable', 'numeric'],
            'coord_y' => ['sometimes', 'nullable', 'numeric'],
            'coord_z' => ['sometimes', 'nullable', 'numeric'],
            'length_cm' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'width_cm' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'height_cm' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'allow_mixed_skus' => ['sometimes', 'boolean'],
            'allow_mixed_lots' => ['sometimes', 'boolean'],
        ];
    }
}
