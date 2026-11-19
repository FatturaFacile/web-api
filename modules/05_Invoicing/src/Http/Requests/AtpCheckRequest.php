<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class AtpCheckRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid', $this->tenantExists('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'lines.*.requested_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
        ];
    }
}
