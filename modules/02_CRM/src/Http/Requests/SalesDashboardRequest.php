<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query of the sales board, the forecasting / quota dashboard and the loss analysis (every filter is optional).
 */
final class SalesDashboardRequest extends FormRequest
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
            'granularity' => ['nullable', 'string', 'in:month,quarter'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'pipeline_id' => ['nullable', 'uuid', $this->tenantOwns('crm_pipelines')],
            'owner_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'forecast_category' => ['nullable', 'string', 'in:pipeline,best_case,commit,closed,omitted'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_column' => ['nullable', 'integer', 'between:1,500'],
        ];
    }
}
