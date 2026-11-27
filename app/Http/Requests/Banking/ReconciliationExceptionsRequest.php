<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Banking\ReconciliationExceptionClassifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the reconciliation review queue: the open bank movements, by cause of the exception.
 */
class ReconciliationExceptionsRequest extends FormRequest
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
            'code' => ['sometimes', Rule::in(ReconciliationExceptionClassifier::CODES)],
            'direction' => ['sometimes', Rule::in(['credit', 'debit'])],
            'statement_id' => ['sometimes', 'uuid', $this->tenantExists('bank_statements')],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ];
    }
}
