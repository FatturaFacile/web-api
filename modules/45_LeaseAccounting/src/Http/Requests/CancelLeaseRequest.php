<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancellation of a draft lease.
 */
final class CancelLeaseRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
