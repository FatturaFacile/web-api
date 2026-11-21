<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataResidency\Domain\Enums\KeyLifecycleAction;

/**
 * The emergency suspension or permanent crypto-shredding: a reason, the typed confirmation phrase and the step-up MFA code.
 */
final class KillSwitchRequest extends FormRequest
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
            'action' => ['required', Rule::in([KeyLifecycleAction::Suspend->value, KeyLifecycleAction::CryptoShred->value])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation_phrase' => ['required', 'string', 'max:64'],
            'mfa_code' => ['required', 'string', 'digits:6'],
        ];
    }
}
