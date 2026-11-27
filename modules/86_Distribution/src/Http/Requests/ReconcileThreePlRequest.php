<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Stock reported by a third-party logistics provider.
 */
final class ReconcileThreePlRequest extends FormRequest
{
    use ScopesToTenant;

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
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'lines.*.external_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
