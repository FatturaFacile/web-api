<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;
use Modules\CorporateConventions\Domain\Enums\WelfareCategory;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;

final class RedeemVoucherRequest extends FormRequest
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
            'source_document_type' => ['required', Rule::in(SourceDocumentType::values())],
            'source_document_id' => ['required', 'uuid'],
            'enrollment_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sales_convention_enrollments', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'jurisdiction' => ['required', Rule::in(WelfareJurisdiction::values())],
            'provider' => ['required', Rule::in(WelfareProvider::values())],
            'welfare_category' => ['required', Rule::in(WelfareCategory::values())],
            'voucher_code' => ['required', 'string', 'min:4', 'max:120'],
            'currency' => ['required', Rule::in(['EUR', 'CHF'])],
            'gross_voucher_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'merchant_fee_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'lt:100'],
        ];
    }
}
