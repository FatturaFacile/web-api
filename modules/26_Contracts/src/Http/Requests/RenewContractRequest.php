<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the renew contract operation (authorization is decided by module.access).
 */
final class RenewContractRequest extends FormRequest
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
        return ['new_end_date' => ['nullable', 'date']];
    }
}
