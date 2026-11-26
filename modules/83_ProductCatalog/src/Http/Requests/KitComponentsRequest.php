<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ProductCatalog\Http\Requests\Concerns\ScopesToTenant;

/**
 * The bill of materials of a kit.
 */
final class KitComponentsRequest extends FormRequest
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
            'components' => ['present', 'array', 'max:200'],
            'components.*.component_id' => ['required', 'uuid', 'distinct', $this->tenantExists('products')],
            'components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'components.*.is_optional' => ['sometimes', 'boolean'],
        ];
    }
}
