<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Selection of approved supplier invoices for a pain.001 payment file.
 */
class ExportPain001Request extends FormRequest
{
    use TenantScopedRules;

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
            'invoice_ids' => ['required', 'array', 'min:1', 'max:500'],
            'invoice_ids.*' => ['uuid', 'distinct', $this->tenantExists('passive_invoices')],
            'version' => ['sometimes', Rule::in(['03', '09'])],
            'scheme' => ['sometimes', Rule::in(['ch', 'it'])],
            'execution_date' => ['nullable', 'date', 'after_or_equal:today'],
            'bank_account_id' => ['nullable', 'uuid', $this->tenantExists('bank_accounts')],
        ];
    }
}
