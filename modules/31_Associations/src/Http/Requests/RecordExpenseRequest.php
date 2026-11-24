<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;

/**
 * An expense of a campaign or of the 5x1000.
 */
final class RecordExpenseRequest extends FormRequest
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
            'incurred_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'expense_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
            'payment_method' => ['sometimes', Rule::in(['cash', 'bank'])],
            'category_id' => ['sometimes', 'uuid', TenantRules::exists('assoc_expense_categories')],
        ];
    }
}
