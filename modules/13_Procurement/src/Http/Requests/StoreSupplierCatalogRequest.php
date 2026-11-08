<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A supplier catalogue entry; with `id` in the route it is an update and every field is optional.
 */
final class StoreSupplierCatalogRequest extends FormRequest
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
        $required = $this->route('id') === null ? 'required' : 'sometimes';
        $supplierId = (string) ($this->input('supplier_id') ?? '');

        return [
            'supplier_id' => [$this->route('id') === null ? 'required' : 'prohibited', 'uuid', $this->tenantOwns('suppliers')],
            'supplier_part_number' => [$required, 'string', 'max:100', Rule::unique('supplier_catalogs', 'supplier_part_number')->where('supplier_id', $supplierId)->whereNull('deleted_at')->ignore($this->route('id'))],
            'internal_sku' => ['nullable', 'string', 'max:100'],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'uom' => ['sometimes', 'string', 'max:20'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'minimum_order_quantity' => ['sometimes', 'decimal:0,4', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
