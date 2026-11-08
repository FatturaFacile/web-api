<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoicing\Http\Requests\Concerns\BillingOverrideRules;

/**
 * A down-payment invoice of a sales order: a percentage of the order net total or an amount, never both.
 */
final class IssueDownPaymentRequest extends FormRequest
{
    use BillingOverrideRules;

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
            'percentage' => ['nullable', 'required_without:amount', 'prohibits:amount', 'numeric', 'gt:0', 'max:100'],
            'amount' => ['nullable', 'required_without:percentage', 'numeric', 'decimal:0,2', 'gt:0'],
            ...$this->overrideRules(),
        ];
    }
}
