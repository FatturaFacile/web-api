<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recomputes the weekly capacity snapshots of a window (one employee, or everyone).
 */
final class RecalculateSnapshotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $from = $this->input('from');
        $latest = is_string($from) && strtotime($from) !== false ? date('Y-m-d', (int) strtotime($from.' +1 year')) : null;

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => array_values(array_filter(['required', 'date_format:Y-m-d', 'after_or_equal:from', $latest === null ? null : 'before_or_equal:'.$latest])),
            'employee_id' => ['nullable', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
