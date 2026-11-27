<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Scope of a replenishment and balancing run.
 */
final class GenerateProposalsRequest extends FormRequest
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
            'warehouse_ids' => ['sometimes', 'array', 'max:200'],
            'warehouse_ids.*' => ['uuid', $this->tenantExists('warehouses')],
            'stock_item_ids' => ['sometimes', 'array', 'max:500'],
            'stock_item_ids.*' => ['uuid', $this->tenantExists('stock_items')],
        ];
    }
}
