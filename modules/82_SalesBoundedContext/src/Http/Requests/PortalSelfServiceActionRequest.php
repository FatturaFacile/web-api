<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\PortalPaymentProvider;

final class PortalSelfServiceActionRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'ddt_invoice_id' => ['nullable', 'uuid'],
            'transaction_code' => ['nullable', 'string', 'max:40'],
            'transaction_type' => ['required', Rule::in(['online_payment', 'qr_bill_download', 'support_ticket'])],
            'payment_provider' => ['nullable', Rule::in(PortalPaymentProvider::values())],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'support_subject' => ['nullable', 'string', 'max:180'],
        ];
    }
}
