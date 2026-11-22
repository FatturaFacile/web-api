<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Queries\GetPortfolioEvmScurveQuery;

/**
 * Filters of the portfolio S-curve: projects, granularity and period.
 */
final class EvmScurveQueryRequest extends FormRequest
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
            'project_ids' => ['nullable', 'array', 'max:200'],
            'project_ids.*' => ['uuid', $this->tenantOwns('projects')],
            'granularity' => ['nullable', 'string', Rule::in(GetPortfolioEvmScurveQuery::Granularities)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
