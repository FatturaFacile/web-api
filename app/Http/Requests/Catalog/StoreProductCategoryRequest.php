<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $ignore = $this->route('id');

        return [
            'name' => [$presence, 'string', 'max:100', Rule::unique('product_categories', 'name')->where('tenant_id', $tenantId)->ignore($ignore)],
            'parent_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId), Rule::notIn([$ignore])],
        ];
    }
}
