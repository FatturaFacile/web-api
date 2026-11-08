<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoicing\Http\Requests\Concerns\BillingOverrideRules;

/**
 * The invoice of one billing plan item: only dates, notes and payment details can be stated.
 */
final class InvoiceBillingPlanItemRequest extends FormRequest
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
        return $this->overrideRules();
    }
}
