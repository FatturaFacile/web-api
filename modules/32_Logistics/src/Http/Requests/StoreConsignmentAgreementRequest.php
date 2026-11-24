<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorization is enforced per route by the logistics.can middleware (RBAC matrix). */
final class StoreConsignmentAgreementRequest extends FormRequest
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
            'warehouse_id' => ['required', 'uuid'],
            'partner_id' => ['nullable', 'uuid'],
            'agreement_number' => ['nullable', 'string', 'max:50'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'billing_frequency' => ['nullable', Rule::in(['monthly', 'quarterly', 'on_demand'])],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
