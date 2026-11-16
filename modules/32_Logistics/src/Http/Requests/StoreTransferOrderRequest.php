<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Logistics\Enums\TransferPurpose;

/** Authorization is enforced per route by the logistics.can middleware (RBAC matrix). */
final class StoreTransferOrderRequest extends FormRequest
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
            'purpose' => ['required', Rule::in(TransferPurpose::values())],
            'origin_warehouse_id' => ['required', 'uuid'],
            'destination_warehouse_id' => ['required', 'uuid'],
            'transit_warehouse_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
