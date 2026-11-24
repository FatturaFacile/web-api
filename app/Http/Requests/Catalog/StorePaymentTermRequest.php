<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentTermRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $ignore = $this->route('id');

        return [
            'code' => [$presence, 'string', 'max:20', Rule::unique('payment_terms', 'code')->where('tenant_id', $tenantId)->ignore($ignore)],
            'name' => [$presence, 'string', 'max:100'],
            'days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'end_of_month' => ['nullable', 'boolean'],
            'discount_percent' => ['nullable', 'numeric', 'gt:0', 'max:100', 'required_with:discount_days'],
            'discount_days' => ['nullable', 'integer', 'min:0', 'required_with:discount_percent'],
            'sdi_terms_code' => ['nullable', Rule::in(['TP01', 'TP02', 'TP03'])],
            'installments' => ['nullable', 'array'],
            'installments.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'installments.*.days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * Installment percentages must add up to exactly 100.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $installments = $this->input('installments');

                if (is_array($installments) && $installments !== []
                    && abs(array_sum(array_column($installments, 'percent')) - 100) > 0.001) {
                    $validator->errors()->add('installments', 'Installment percentages must sum to 100.');
                }
            },
        ];
    }
}
