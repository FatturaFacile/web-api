<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ResourcePlanning\Domain\Enums\RebalanceStrategy;

/**
 * Moves, hands over, splits or resizes an allocation.
 */
final class RebalanceAllocationRequest extends FormRequest
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

        return [
            'strategy' => ['required', Rule::enum(RebalanceStrategy::class)],
            'employee_id' => ['nullable', 'uuid', 'required_if:strategy,'.RebalanceStrategy::Reassign->value, Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'split_date' => ['nullable', 'date_format:Y-m-d', 'required_if:strategy,'.RebalanceStrategy::Split->value],
            'shift_days' => ['nullable', 'integer', 'between:-3650,3650'],
            'allocated_hours_per_day' => ['nullable', 'numeric', 'gt:0', 'max:24'],
        ];
    }
}
