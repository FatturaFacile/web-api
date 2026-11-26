<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ProductCatalog\Domain\Enums\DeltaType;
use Modules\ProductCatalog\Http\Requests\Concerns\ScopesToTenant;

/**
 * Create and update of an attribute with its values. Names and labels are maps locale => text (it, de, fr, en).
 */
final class SaveAttributeRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', $this->tenantUnique('catalog_attributes', 'code')] : ['prohibited'],
            'name' => [$required, 'array'],
            'name.en' => [$required, 'string', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'sku_token' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'values' => ['sometimes', 'array', 'max:200'],
            'values.*.code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', 'distinct:ignore_case'],
            'values.*.label' => ['required', 'array'],
            'values.*.label.en' => ['required', 'string', 'max:80'],
            'values.*.label.*' => ['nullable', 'string', 'max:80'],
            'values.*.sku_token' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'values.*.price_delta' => ['sometimes', 'nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'values.*.price_delta_type' => ['sometimes', 'nullable', Rule::enum(DeltaType::class)],
            'values.*.cost_delta' => ['sometimes', 'nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'values.*.cost_delta_type' => ['sometimes', 'nullable', Rule::enum(DeltaType::class)],
            'values.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'values.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
