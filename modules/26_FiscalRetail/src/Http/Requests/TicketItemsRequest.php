<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lines of a ticket to price and discount (promotion preview): price and VAT default to the product master data.
 */
class TicketItemsRequest extends FormRequest
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
        return $this->itemRules('items');
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemRules(string $prefix): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            $prefix => ['required', 'array', 'min:1'],
            "{$prefix}.*.product_id" => ['nullable', 'uuid', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            "{$prefix}.*.description" => ["required_without:{$prefix}.*.product_id", 'nullable', 'string', 'max:1000'],
            "{$prefix}.*.quantity" => ['required', 'numeric', 'gt:0'],
            "{$prefix}.*.unit_price" => ["required_without:{$prefix}.*.product_id", 'nullable', 'numeric', 'min:0'],
            "{$prefix}.*.tax_rate" => ["required_without:{$prefix}.*.product_id", 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentRules(string $prefix): array
    {
        return [
            $prefix => ['nullable', 'array', 'max:10'],
            "{$prefix}.*.method" => ['required', Rule::in(['cash', 'card', 'voucher', 'bank_transfer', 'mobile', 'other'])],
            "{$prefix}.*.amount" => ['required', 'numeric', 'gt:0'],
            "{$prefix}.*.reference" => ['nullable', 'string', 'max:120'],
        ];
    }
}
