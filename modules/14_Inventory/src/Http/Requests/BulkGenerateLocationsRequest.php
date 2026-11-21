<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Services\LocationTopologyService;

/**
 * The layout of a zone to generate in one batch: how many aisles, racks, shelves and bins, and the limits and size every
 * generated bin gets.
 */
final class BulkGenerateLocationsRequest extends FormRequest
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
            'zone_code' => ['required', 'string', 'max:50'],
            'zone_name' => ['nullable', 'string', 'max:100'],
            'zone_type' => ['nullable', 'string', 'in:receiving,storage,picking,packing,shipping,quarantine,transit'],
            'aisles' => ['required', 'integer', 'min:1', 'max:'.LocationTopologyService::MAX_BULK_BINS],
            'racks' => ['required', 'integer', 'min:1', 'max:'.LocationTopologyService::MAX_BULK_BINS],
            'shelves' => ['required', 'integer', 'min:1', 'max:'.LocationTopologyService::MAX_BULK_BINS],
            'bins' => ['required', 'integer', 'min:1', 'max:'.LocationTopologyService::MAX_BULK_BINS],
            'max_weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'max_volume_cbm' => ['nullable', 'numeric', 'gt:0'],
            'max_quantity' => ['nullable', 'numeric', 'gt:0'],
            'length_cm' => ['nullable', 'numeric', 'gt:0'],
            'width_cm' => ['nullable', 'numeric', 'gt:0'],
            'height_cm' => ['nullable', 'numeric', 'gt:0'],
            'spacing' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
