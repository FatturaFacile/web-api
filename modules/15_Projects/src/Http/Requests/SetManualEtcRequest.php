<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual bottom-up estimate to complete of a project or WBS node; a null estimate restores the BAC / CPI forecast.
 */
final class SetManualEtcRequest extends FormRequest
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
            'wbs_node_id' => ['nullable', 'uuid', $this->tenantOwns('project_tasks')],
            'estimate_to_complete' => ['present', 'nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
