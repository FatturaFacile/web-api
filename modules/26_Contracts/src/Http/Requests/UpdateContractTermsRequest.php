<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the update contract terms operation (authorization is decided by module.access).
 */
final class UpdateContractTermsRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
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
            'auto_renew' => ['sometimes', 'boolean'],
            'notice_period_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'renewal_term_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'alert_days_before' => ['sometimes', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
