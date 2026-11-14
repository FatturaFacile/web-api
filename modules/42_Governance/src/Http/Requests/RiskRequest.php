<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovRisk;

/**
 * A risk of the register, on creation (POST) or change (PUT). The scores are computed by the service.
 */
final class RiskRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'category' => [$presence, Rule::in(GovRisk::Categories)],
            'likelihood' => [$presence, 'integer', 'between:1,5'],
            'impact' => [$presence, 'integer', 'between:1,5'],
            'residual_likelihood' => ['nullable', 'integer', 'between:1,5', 'required_with:residual_impact'],
            'residual_impact' => ['nullable', 'integer', 'between:1,5', 'required_with:residual_likelihood'],
            'treatment' => ['nullable', Rule::in(GovRisk::Treatments)],
            'treatment_notes' => ['nullable', 'string', 'max:4000'],
            'owner_id' => ['nullable', 'uuid', $this->tenantMember()],
            'next_review_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
