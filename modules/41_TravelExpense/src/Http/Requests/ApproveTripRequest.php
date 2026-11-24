<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval of a trip, optionally changing the advance that is going to be paid.
 */
final class ApproveTripRequest extends FormRequest
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
            'advance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
