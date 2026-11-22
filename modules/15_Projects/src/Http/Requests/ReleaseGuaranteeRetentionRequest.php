<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Release of the guarantee retention of a project, in full when no amount is given (Mod 081).
 */
final class ReleaseGuaranteeRetentionRequest extends FormRequest
{
    use TenantScopedRules;

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
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'release_date' => ['nullable', 'date_format:Y-m-d'],
            'acceptance_confirmed' => ['nullable', 'boolean'],
            'milestone_id' => ['nullable', 'uuid', $this->tenantOwns('project_milestones')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
