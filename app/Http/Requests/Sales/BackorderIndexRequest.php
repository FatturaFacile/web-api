<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the backorder list: customer, product, page size.
 */
class BackorderIndexRequest extends FormRequest
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
            'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
