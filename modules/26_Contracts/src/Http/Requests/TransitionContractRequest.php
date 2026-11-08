<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Contracts\Enums\ContractStatus;

/**
 * Validates a lifecycle move of a contract. Terminating needs a reason (access is decided by module.access and the
 * contracts.terminate gate).
 */
final class TransitionContractRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the controller.
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
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'reason' => [Rule::requiredIf(fn (): bool => $this->input('status') === ContractStatus::Terminated->value), 'nullable', 'string', 'max:1000'],
        ];
    }
}
