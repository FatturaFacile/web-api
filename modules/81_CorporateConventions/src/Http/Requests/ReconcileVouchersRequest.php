<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;

/**
 * Settlement statement of a provider (POST welfare-vouchers/reconcile) or the batch of vouchers sent to it (POST welfare-vouchers/submit-batch).
 */
final class ReconcileVouchersRequest extends FormRequest
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
        $submit = $this->routeIs('corporate-conventions.welfare.submit-batch');
        $voucher = Rule::exists('pos_welfare_voucher_redemptions', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        return [
            'provider' => ['required', Rule::in(WelfareProvider::values())],
            'batch_reference' => ['required', 'string', 'max:80'],
            'self_invoice_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'redemption_ids' => [$submit ? 'required' : 'prohibited', 'array', 'min:1', 'max:1000'],
            'redemption_ids.*' => ['uuid', 'distinct', $voucher],
            'lines' => [$submit ? 'prohibited' : 'required', 'array', 'min:1', 'max:1000'],
            'lines.*.redemption_id' => ['required_without:lines.*.voucher_code', 'nullable', 'uuid', $voucher],
            'lines.*.voucher_code' => ['required_without:lines.*.redemption_id', 'nullable', 'string', 'max:120'],
            'lines.*.settled_amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
