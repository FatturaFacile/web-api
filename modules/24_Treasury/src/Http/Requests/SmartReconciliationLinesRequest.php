<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the smart reconciliation workbench (query string of GET /treasury/smart-reconciliation/lines).
 */
final class SmartReconciliationLinesRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(['open', 'reconciled', 'all'])],
            'direction' => ['sometimes', Rule::in(['credit', 'debit'])],
            'statement_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'min_confidence' => ['sometimes', 'integer', 'between:0,100'],
            'with_proposals' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
