<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovSodRule;

/**
 * A segregation of duties rule, on creation (POST) or change (PUT). The code is set on creation only.
 */
final class SodRuleRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $duty = ['string', 'max:120', 'regex:/^[a-z0-9_.*:-]+$/i'];

        return [
            'code' => $this->isMethod('POST') ? ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/'] : ['prohibited'],
            'title' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'duties_a' => [$presence, 'array', 'min:1', 'max:20'],
            'duties_a.*' => ['required', ...$duty],
            'duties_b' => [$presence, 'array', 'min:1', 'max:20'],
            'duties_b.*' => ['required', ...$duty],
            'severity' => ['sometimes', Rule::in(GovSodRule::Severities)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
