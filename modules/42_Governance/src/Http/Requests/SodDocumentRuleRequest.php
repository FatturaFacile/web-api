<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovSodDocumentRule;

/**
 * Configures the per-document segregation of duties rule of one kind of document.
 */
final class SodDocumentRuleRequest extends FormRequest
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
            'severity' => ['required', Rule::in(GovSodDocumentRule::Severities)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
