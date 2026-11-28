<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * Month (YYYY-MM) of the F24 and UniEmens flows.
 */
final class MonthFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settle', RecipientSettlement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accrual_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
