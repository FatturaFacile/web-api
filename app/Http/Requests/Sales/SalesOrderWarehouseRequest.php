<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The warehouse an order is checked against or reserved in (the primary warehouse of the company when omitted).
 */
class SalesOrderWarehouseRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
        ];
    }
}
