<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Services\PaymentFlows\PaymentFlow;

/**
 * Lines of an outgoing payment file (Swiss pain.001.001.09, SEPA pain.001.001.03, CBI). Business rules (IBAN checksums,
 * currency of the flow, QR references) are checked by the payment flow service, which reports every problem at once.
 */
final class PaymentFlowRequest extends FormRequest
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
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return [
            'flow' => ['required', Rule::enum(PaymentFlow::class)],
            'bank_account_id' => ['required', 'uuid', Rule::exists('bank_accounts', 'id')->where('tenant_id', $tenantId)],
            'execution_date' => ['required', 'date', 'after_or_equal:today'],
            'currency' => ['nullable', 'string', 'size:3'],
            'cuc' => ['nullable', 'string', 'max:8'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.recipient_name' => ['required', 'string', 'max:140'],
            'lines.*.recipient_iban' => ['required', 'string', 'max:42'],
            'lines.*.recipient_bic' => ['nullable', 'string', 'max:11'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
            'lines.*.remittance_reference' => ['nullable', 'string', 'max:200'],
            'lines.*.structured_creditor_ref' => ['nullable', 'string', 'max:35'],
            'lines.*.end_to_end_id' => ['nullable', 'string', 'max:35'],
            'lines.*.invoice_id' => ['nullable', 'uuid'],
        ];
    }
}
