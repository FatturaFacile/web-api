<?php

declare(strict_types=1);

namespace App\Http\Requests\Tax;

use App\Http\Requests\Concerns\ItalianFiscalRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An unsaved Italian invoice payload whose fiscal figures are previewed.
 */
class FiscalPreviewRequest extends FormRequest
{
    use ItalianFiscalRules;

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

        return array_merge([
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId), 'required_with:enasarco'],
            'date' => ['nullable', 'date'],
            'split_payment' => ['nullable', 'boolean'],
            'reverse_charge' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], $this->italianFiscalRules());
    }
}
