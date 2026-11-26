<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ProductCatalog\Http\Requests\Concerns\ScopesToTenant;

/**
 * Commercial lines (order, invoice) to resolve into picking and transport document lines.
 */
final class KitExplodeRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', $this->tenantExists('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.include_optional' => ['sometimes', 'boolean'],
        ];
    }
}
