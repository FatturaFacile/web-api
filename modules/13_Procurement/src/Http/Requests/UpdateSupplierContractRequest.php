<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSupplierContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'contract_number' => ['sometimes', 'string', 'max:100'],
            'title' => ['sometimes', 'string', 'max:255'],
            'contract_type' => ['sometimes', 'string', 'in:framework,service_level,standard,blanket'],
            'status' => ['sometimes', 'string', 'in:draft,active,expired,terminated'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'total_committed_amount' => ['nullable', 'numeric', 'gte:0'],
            'consumed_amount' => ['nullable', 'numeric', 'gte:0'],
            'payment_terms_code' => ['nullable', 'string', 'max:50'],
            'incoterms' => ['nullable', 'string', 'max:10'],
        ];
    }
}
