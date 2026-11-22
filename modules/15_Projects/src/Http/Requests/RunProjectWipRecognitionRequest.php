<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Support\BillingStatuses;

/**
 * Period-end WIP valuation of a project, or of every open project when `project_id` is omitted (Mod 081).
 */
final class RunProjectWipRecognitionRequest extends FormRequest
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
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'period_end_date' => ['required', 'date_format:Y-m-d'],
            'valuation_method' => ['nullable', 'string', Rule::in(BillingStatuses::WipMethods)],
        ];
    }
}
