<?php

declare(strict_types=1);

namespace App\Http\Requests\Retail;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
