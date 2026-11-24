<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCommercialDocumentRequest extends FormRequest
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

        return [
            'pos_sale_id' => ['nullable', 'uuid', Rule::exists('pos_sales', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'lines' => ['required_without:pos_sale_id', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['required', 'numeric', 'between:0,100'],
            'lines.*.vat_nature' => ['nullable', 'string', 'max:10'],
        ];
    }
}
