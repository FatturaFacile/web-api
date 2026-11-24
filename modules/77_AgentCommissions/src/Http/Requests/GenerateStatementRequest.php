<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AgentCommissions\Domain\Enums\PeriodType;
use Modules\AgentCommissions\Models\Statement;

/**
 * Generates a statement, or previews it (the period type is optional for a preview).
 */
final class GenerateStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Statement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'agent_profile_id' => ['required', 'uuid', Rule::exists('agc_agent_profiles', 'id')->where('tenant_id', $tenantId)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'period_type' => ['sometimes', Rule::enum(PeriodType::class)],
        ];
    }
}
