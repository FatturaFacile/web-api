<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\CustomerCreditOverride;

/**
 * Override of a credit block: scope, mandatory reason and optional expiry.
 */
final class GrantCreditOverrideRequest extends FormRequest
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
            'scope' => ['required', Rule::in(CustomerCreditOverride::Scopes)],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
