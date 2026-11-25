<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;

/**
 * The cart of the till, to be priced (POST pos/pricing) or committed (POST pos/checkout).
 */
final class CartRequest extends FormRequest
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
        $checkout = $this->routeIs('corporate-conventions.pos.checkout');

        return [
            'identifier' => [$checkout ? 'sometimes' : 'required', 'string', 'max:190'],
            'enrollment_id' => [$checkout ? 'required' : 'sometimes', 'uuid', Rule::exists('sales_convention_enrollments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'convention_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sales_partner_conventions', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'currency' => ['sometimes', 'nullable', Rule::in(['EUR', 'CHF'])],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => ['sometimes', 'string', 'max:60', 'distinct'],
            'lines.*.product_id' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.unit_price' => ['required_without:lines.*.product_id', 'nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.category_id' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.is_promotional' => ['sometimes', 'boolean'],
            'lines.*.existing_discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'source_document_type' => [$checkout ? 'required' : 'prohibited', Rule::in(SourceDocumentType::values())],
            'source_document_id' => [$checkout ? 'required' : 'prohibited', 'uuid'],
            'employee_document_id' => ['sometimes', 'nullable', 'uuid'],
            'transaction_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
