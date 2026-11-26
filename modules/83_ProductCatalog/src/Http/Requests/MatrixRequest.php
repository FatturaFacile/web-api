<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ProductCatalog\Http\Requests\Concerns\ScopesToTenant;

/**
 * The axes of a variant matrix (attribute and chosen values), with optional per-combination overrides keyed by the value ids of
 * the combination joined with `|` in axis order.
 */
final class MatrixRequest extends FormRequest
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
            'axes' => ['required', 'array', 'min:1', 'max:6'],
            'axes.*.attribute_id' => ['required', 'uuid', 'distinct', $this->tenantExists('catalog_attributes')],
            'axes.*.value_ids' => ['required', 'array', 'min:1', 'max:100'],
            'axes.*.value_ids.*' => ['uuid', 'distinct', $this->tenantExists('catalog_attribute_values')],
            'overrides' => ['sometimes', 'array'],
            'overrides.*.sku' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'overrides.*.barcode' => ['sometimes', 'nullable', 'string', 'max:14', 'regex:/^\d+$/'],
            'overrides.*.sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'overrides.*.cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'generate_barcodes' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<array{attribute_id: string, value_ids: list<string>}>
     */
    public function axes(): array
    {
        return array_map(static fn (array $axis): array => [
            'attribute_id' => (string) $axis['attribute_id'],
            'value_ids' => array_values(array_map('strval', $axis['value_ids'])),
        ], array_values((array) $this->validated('axes')));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function overrides(): array
    {
        return (array) ($this->validated('overrides') ?? []);
    }
}
