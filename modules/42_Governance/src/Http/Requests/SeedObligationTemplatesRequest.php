<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovObligation;

/**
 * Creates the compliance calendar entries of the chosen frameworks from the seeded templates.
 */
final class SeedObligationTemplatesRequest extends FormRequest
{
    use TenantScopedRules;

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
            'frameworks' => ['required', 'array', 'min:1'],
            'frameworks.*' => ['distinct', Rule::in(array_diff(GovObligation::Frameworks, ['other']))],
            'first_due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'owner_id' => ['nullable', 'uuid', $this->tenantMember()],
        ];
    }
}
