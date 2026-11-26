<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\ProductType;

/**
 * Filters, paging and the export format of the catalog lists.
 */
final class ListProductsRequest extends FormRequest
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
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['catalog', 'variants', 'all'])],
            'product_type' => ['sometimes', Rule::enum(ProductType::class)],
            'kit_type' => ['sometimes', Rule::enum(KitType::class)],
            'category_id' => ['sometimes', 'uuid'],
            'country_of_origin' => ['sometimes', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'format' => ['sometimes', Rule::in(['csv', 'xlsx'])],
        ];
    }
}
