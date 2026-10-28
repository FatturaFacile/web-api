<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class SalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rule prefix: full validation on create, partial on update.
     */
    protected function presence(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $presence = $this->presence();

        return [
            'contact_id' => [$presence, 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'date' => [$presence, 'date'],
            'currency' => [$presence, 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$presence, 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
