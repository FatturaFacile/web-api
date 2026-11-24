<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Requests;

use Illuminate\Validation\Rule;

final class MarginApprovalRequest extends PricingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['sales_quote', 'sales_order'])],
            'document_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
