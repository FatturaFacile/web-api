<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A change of status of a risk, audit plan, audit, finding, action or obligation. Which targets are allowed from the
 * current status is decided by the state machine of the record.
 */
final class TransitionRequest extends FormRequest
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
            'status' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
