<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An item of a framework agreement with its agreed price and validity.
 */
final class StoreContractLineRequest extends FormRequest
{
    use TenantScopedRules;

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
            'supplier_catalog_id' => ['nullable', 'uuid', $this->tenantOwns('supplier_catalogs')],
            'description' => ['required', 'string', 'max:255'],
            'uom' => ['nullable', 'string', 'max:20'],
            'unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'tax_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'committed_quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'price_valid_from' => ['required', 'date_format:Y-m-d'],
            'price_valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:price_valid_from'],
        ];
    }
}
