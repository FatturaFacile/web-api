<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming a zone or changing its type. A quarantine or transit zone keeps its stock out of orders, picks and sales.
 */
final class UpdateWarehouseZoneRequest extends FormRequest
{
    public const array ZONE_TYPES = ['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'transit'];

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
            'name' => ['sometimes', 'string', 'max:100'],
            'zone_type' => ['sometimes', 'string', Rule::in(self::ZONE_TYPES)],
        ];
    }
}
